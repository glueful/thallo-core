<?php

declare(strict_types=1);

namespace Thallo\Core\Http\Controllers;

use Thallo\Core\Events\MediaDeleted;
use Glueful\Auth\Contracts\UserProviderInterface;
use Glueful\Auth\UserIdentity;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Events\EventService;
use Glueful\Helpers\Utils;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Support\SignedUrl;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Media-library admin API over the framework `blobs` store.
 *
 * The framework ships only per-uuid blob ops (upload/show/delete); this adds the library view the
 * SPA needs — a paginated, type-filtered, searchable list — plus Thallo's CMS sidecars:
 *  - media_meta  (alt text / caption / tags), edited via {@see update()};
 *  - media_usage (which entries reference a blob), surfaced via {@see usage()} and maintained by
 *    {@see \Thallo\Core\Content\Pipeline\Listeners\MediaUsageProjector} off the asset events.
 *
 * Gated by `content.view` (read) / `content.manage` (write) — see routes/admin.php.
 */
final class MediaAdminController
{
    private const PER_PAGE_MAX = 60;

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly SystemFlags $flags,
    ) {
    }

    /** GET /v1/admin/media — paginated, type-filtered, searchable library. */
    #[ApiOperation(
        summary: 'List media',
        description: 'Paginated blob library. Optional `type` (image|video|audio|doc), `q` (name '
            . 'search), `page`, `per_page`. Requires the `content.view` permission.',
        tags: ['Media'],
    )]
    #[ApiResponse(200, description: 'Media page.')]
    public function index(Request $request): Response
    {
        $page = max(1, (int) $request->query->get('page', '1'));
        $perPage = min(self::PER_PAGE_MAX, max(1, (int) $request->query->get('per_page', '30')));

        $query = $this->activeBlobs();

        $this->applyTypeFilter($query, (string) $request->query->get('type', ''), 'b.mime_type');

        $search = trim((string) $request->query->get('q', ''));
        if ($search !== '') {
            $query->where('b.name', 'LIKE', '%' . $search . '%');
        }

        $query->orderBy('b.created_at', 'desc');

        /** @var array{data:array<int,array<string,mixed>>,total:int,current_page:int,per_page:int} $result */
        $result = $query->paginate($page, $perPage);

        $host = $request->getSchemeAndHttpHost();
        $items = array_map(fn (array $b): array => $this->presentRow($b, $host), array_values($result['data']));

        return Response::success([
            'media' => $items,
            'total' => $result['total'],
            'current_page' => $result['current_page'],
            'per_page' => $result['per_page'],
        ], 'Media retrieved.');
    }

    /** GET /v1/admin/media/{uuid} — one blob with its metadata + usage count. */
    #[ApiOperation(summary: 'Get a media item', tags: ['Media'])]
    #[ApiResponse(200, description: 'Media item.')]
    #[ApiResponse(404, description: 'No such media.')]
    public function show(Request $request, string $uuid): Response
    {
        $blob = $this->findBlob($uuid);
        if ($blob === null) {
            return Response::notFound('Media not found.');
        }

        $meta = db($this->context)->table('media_meta')->where('blob_uuid', '=', $uuid)->first();
        $usageCount = db($this->context)->table('media_usage')->where('blob_uuid', '=', $uuid)->count();

        return Response::success(
            [
                'media' => $this->present(
                    $blob,
                    is_array($meta) ? $meta : null,
                    (int) $usageCount,
                    $request->getSchemeAndHttpHost(),
                ),
            ],
            'Media retrieved.',
        );
    }

    /** PATCH /v1/admin/media/{uuid} — edit title + alt/caption/tags. */
    #[ApiOperation(
        summary: 'Update media metadata',
        description: 'Updates the title (blob name) and the CMS sidecar (alt text, caption, tags). '
            . 'Requires the `content.manage` permission.',
        tags: ['Media'],
    )]
    #[ApiResponse(200, description: 'Updated media item.')]
    #[ApiResponse(404, description: 'No such media.')]
    public function update(Request $request, string $uuid): Response
    {
        $blob = $this->findBlob($uuid);
        if ($blob === null) {
            return Response::notFound('Media not found.');
        }

        /** @var array<string,mixed> $input */
        $input = json_decode((string) $request->getContent(), true) ?: [];
        $now = date('Y-m-d H:i:s');

        $title = $this->stringField($input, 'title');
        if ($title !== null && $title !== '') {
            db($this->context)->table('blobs')
                ->where('uuid', '=', $uuid)
                ->update(['name' => $title, 'updated_at' => $now]);
        }

        // media_meta is upserted only when a sidecar field is actually present in the payload.
        if (
            array_key_exists('alt_text', $input)
            || array_key_exists('caption', $input)
            || array_key_exists('tags', $input)
        ) {
            $this->upsertMeta($uuid, [
                'alt_text' => $this->stringField($input, 'alt_text'),
                'caption' => $this->stringField($input, 'caption'),
                'tags' => $this->tagsField($input),
            ], $now);
        }

        return $this->show($request, $uuid);
    }

    /** DELETE /v1/admin/media/{uuid} — soft-delete a blob. */
    #[ApiOperation(
        summary: 'Delete a media item',
        description: 'Soft-deletes the blob (status=deleted). Requires the `content.manage` permission.',
        tags: ['Media'],
    )]
    #[ApiResponse(200, description: 'Deleted.')]
    #[ApiResponse(404, description: 'No such media.')]
    public function destroy(Request $request, string $uuid): Response
    {
        $blob = $this->findBlob($uuid);
        if ($blob === null) {
            return Response::notFound('Media not found.');
        }

        db($this->context)->table('blobs')
            ->where('uuid', '=', $uuid)
            ->update(['status' => 'deleted', 'deleted_at' => date('Y-m-d H:i:s')]);

        // The raw status update bypasses BlobRepository's entity events, so audit the deletion
        // explicitly — otherwise media deletes go unrecorded (uploads are audited via the repo).
        [$actorUuid, $actorLabel] = $this->actor($request);
        app($this->context, EventService::class)->dispatch(new MediaDeleted(
            $uuid,
            isset($blob['name']) ? (string) $blob['name'] : null,
            $actorUuid,
            $actorLabel,
        ));

        return Response::success(['deleted' => true], 'Media deleted.');
    }

    /**
     * The acting user's uuid + display label (email/username) from the request principal.
     *
     * @return array{0:?string,1:?string}
     */
    private function actor(Request $request): array
    {
        $uuid = null;
        $label = null;

        $user = $request->attributes->get('auth.user');
        if ($user instanceof UserIdentity) {
            $uuid = $user->uuid();
            $label = $user->email() ?? $user->username();
        } else {
            // Fallback: the always-present post-auth `'user'` array attribute.
            $raw = $request->attributes->get('user');
            if (is_array($raw) && isset($raw['uuid']) && is_string($raw['uuid']) && $raw['uuid'] !== '') {
                $uuid = $raw['uuid'];
                $email = is_string($raw['email'] ?? null) ? $raw['email'] : null;
                $username = is_string($raw['username'] ?? null) ? $raw['username'] : null;
                $label = $email ?? $username;
            }
        }

        // The request principal usually carries only the actor uuid (no email/username), so resolve a
        // display label from the user store — otherwise the audit row shows a bare uuid. (Uploads get
        // this for free via the audit layer's created_by fallback; the delete event must supply it.)
        if ($uuid !== null && ($label === null || $label === '')) {
            $label = $this->resolveLabel($uuid);
        }

        return [$uuid, $label];
    }

    /** Best-effort email/username for a user uuid via the user store. Never throws. */
    private function resolveLabel(string $uuid): ?string
    {
        try {
            if (!$this->context->hasContainer()) {
                return null;
            }
            $container = $this->context->getContainer();
            if (!$container->has(UserProviderInterface::class)) {
                return null;
            }
            $identity = $container->get(UserProviderInterface::class)->findByUuid($uuid);

            return $identity?->email() ?? $identity?->username();
        } catch (\Throwable) {
            return null;
        }
    }

    /** POST /v1/admin/media/{uuid}/optimize — re-encode an image smaller, in place. */
    #[ApiOperation(
        summary: 'Optimize an image',
        description: 'Re-encodes the image to reduce file size (dimensions unchanged) and writes it '
            . 'back to the same blob. Requires glueful/media and the `content.manage` permission.',
        tags: ['Media'],
    )]
    #[ApiResponse(200, description: 'Optimized media + before/after sizes.')]
    #[ApiResponse(404, description: 'No such media.')]
    #[ApiResponse(422, description: 'Not an image.')]
    public function optimize(Request $request, string $uuid): Response
    {
        $blob = $this->findBlob($uuid);
        if ($blob === null) {
            return Response::notFound('Media not found.');
        }
        if (!str_starts_with((string) ($blob['mime_type'] ?? ''), 'image/')) {
            return Response::error('Only images can be optimized.', 422);
        }
        if (!class_exists(\Glueful\Extensions\Media\ImageProcessor::class)) {
            return Response::error('Image optimization is unavailable (glueful/media not installed).', 503);
        }

        $disk = (string) ($blob['storage_type'] ?? '') !== ''
            ? (string) $blob['storage_type']
            : (string) config($this->context, 'uploads.disk', 'uploads');
        $path = (string) ($blob['url'] ?? '');

        $storage = app($this->context, \Glueful\Storage\StorageManager::class);
        $src = null;
        try {
            $contents = (string) $storage->disk($disk)->read($path);
            $originalSize = strlen($contents);

            // ImageProcessor::make() reads from a path (it detects the format from the bytes).
            $src = (string) tempnam(sys_get_temp_dir(), 'opt-src-');
            file_put_contents($src, $contents);

            // Re-encode (optimize) to the same format and read the bytes back directly — getImageData()
            // avoids save()'s need to infer a format from a file extension.
            $format = $this->imageFormatFromMime((string) ($blob['mime_type'] ?? ''));
            $processor = \Glueful\Extensions\Media\ImageProcessor::make($src, $this->context)->optimize();
            $optimized = $format !== null ? $processor->getImageData($format) : $processor->getImageData();
            $newSize = strlen($optimized);

            // Don't write back if it didn't actually shrink (re-encoding an already-optimal image can
            // be the same or larger) — keep the original bytes.
            if ($optimized === '' || $newSize >= $originalSize) {
                return $this->optimizeResult($request, $uuid, $originalSize, $originalSize, false);
            }

            $storage->disk($disk)->write($path, $optimized);
            db($this->context)->table('blobs')
                ->where('uuid', '=', $uuid)
                ->update(['size' => $newSize, 'updated_at' => date('Y-m-d H:i:s')]);

            return $this->optimizeResult($request, $uuid, $originalSize, $newSize, true);
        } catch (\Throwable $e) {
            return Response::error('Could not optimize the image: ' . $e->getMessage(), 500);
        } finally {
            if ($src !== null && is_file($src)) {
                @unlink($src);
            }
        }
    }

    private function imageFormatFromMime(string $mime): ?string
    {
        return match ($mime) {
            'image/jpeg', 'image/jpg' => 'jpeg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => null,
        };
    }

    private function optimizeResult(
        Request $request,
        string $uuid,
        int $originalSize,
        int $newSize,
        bool $changed,
    ): Response {
        $blob = $this->findBlob($uuid);
        $meta = $blob !== null
            ? db($this->context)->table('media_meta')->where('blob_uuid', '=', $uuid)->first()
            : null;
        $usageCount = db($this->context)->table('media_usage')->where('blob_uuid', '=', $uuid)->count();

        return Response::success([
            'media' => $blob !== null
                ? $this->present(
                    $blob,
                    is_array($meta) ? $meta : null,
                    (int) $usageCount,
                    $request->getSchemeAndHttpHost(),
                )
                : null,
            'original_size' => $originalSize,
            'new_size' => $newSize,
            'changed' => $changed,
        ], $changed ? 'Image optimized.' : 'Image is already optimal.');
    }

    /** GET /v1/admin/media/{uuid}/usage — entries that reference this blob. */
    #[ApiOperation(summary: 'Where a media item is used', tags: ['Media'])]
    #[ApiResponse(200, description: 'Referencing entries.')]
    public function usage(string $uuid): Response
    {
        $rows = db($this->context)->table('media_usage')
            ->where('blob_uuid', '=', $uuid)
            ->get();

        $entryUuids = array_values(array_filter(array_map(
            static fn (array $r): string => (string) ($r['entry_uuid'] ?? ''),
            $rows,
        )));

        $entries = [];
        $typeUuids = [];
        if ($entryUuids !== []) {
            foreach (db($this->context)->table('entries')->whereIn('uuid', $entryUuids)->get() as $e) {
                $entries[(string) $e['uuid']] = $e;
                if (is_string($e['content_type_uuid'] ?? null)) {
                    $typeUuids[$e['content_type_uuid']] = true;
                }
            }
        }

        // Resolve content-type uuids to slugs for a human-readable label.
        $typeSlugs = [];
        if ($typeUuids !== []) {
            $types = db($this->context)->table('content_types')->whereIn('uuid', array_keys($typeUuids))->get();
            foreach ($types as $ct) {
                $typeSlugs[(string) $ct['uuid']] = is_string($ct['slug'] ?? null) ? $ct['slug'] : null;
            }
        }

        $usage = array_map(static function (array $r) use ($entries, $typeSlugs): array {
            $uuid = (string) ($r['entry_uuid'] ?? '');
            $entry = $entries[$uuid] ?? null;
            $typeUuid = is_array($entry) && is_string($entry['content_type_uuid'] ?? null)
                ? $entry['content_type_uuid']
                : null;
            return [
                'entry_uuid' => $uuid,
                'type' => $typeUuid !== null ? ($typeSlugs[$typeUuid] ?? null) : null,
                'status' => is_array($entry) && is_string($entry['status'] ?? null) ? $entry['status'] : null,
            ];
        }, $rows);

        return Response::success(['usage' => array_values($usage)], 'Usage retrieved.');
    }

    // ---- Helpers --------------------------------------------------------------

    /** @return array<string,mixed>|null */
    private function findBlob(string $uuid): ?array
    {
        $blob = $this->activeBlobs()->where('b.uuid', '=', $uuid)->first();

        return is_array($blob) ? $blob : null;
    }

    /**
     * Base query over active, non-deleted blobs, aliased `b`.
     *
     * When tenancy is on, `media_assets` is the primary table so the framework tenant read-hook
     * auto-scopes the library to the resolved tenant. When off there is no ledger to join — blobs
     * are queried directly, so tenancy-off uploads (which never get a ledger row) stay visible.
     */
    private function activeBlobs(): object
    {
        $db = db($this->context);
        $query = $this->flags->tenancyEnabled()
            ? $db->table('media_assets as ma')->join('blobs as b', 'b.uuid', '=', 'ma.blob_uuid')
            : $db->table('blobs as b');

        return $query->select(['b.*'])
            ->where('b.status', '=', 'active')
            ->whereNull('b.deleted_at');
    }

    private function applyTypeFilter(object $query, string $type, string $column = 'mime_type'): void
    {
        match ($type) {
            'image', 'video', 'audio' => $query->where($column, 'LIKE', $type . '/%'),
            'doc' => $query->whereRaw(
                "{$column} NOT LIKE 'image/%' AND {$column} NOT LIKE 'video/%' "
                . "AND {$column} NOT LIKE 'audio/%'"
            ),
            default => null,
        };
    }

    /**
     * @param array<string,mixed> $blob
     * @return array<string,mixed>
     */
    private function presentRow(array $blob, string $host): array
    {
        return [
            'uuid' => (string) ($blob['uuid'] ?? ''),
            'name' => (string) ($blob['name'] ?? ''),
            'mime_type' => (string) ($blob['mime_type'] ?? ''),
            'size' => (int) ($blob['size'] ?? 0),
            'url' => (string) ($blob['url'] ?? ''),
            'visibility' => (string) ($blob['visibility'] ?? 'private'),
            // Ready-to-render URLs: public blobs serve directly; private ones get a short-lived
            // signed URL so the SPA can use it in <img>. thumb_url is a small on-the-fly variant
            // for the list/grid (faster than loading the full image).
            'display_url' => $this->serveUrl($blob, $host),
            'thumb_url' => $this->variantUrl($blob, $host, 160),
            'created_at' => $blob['created_at'] ?? null,
        ];
    }

    /**
     * @param array<string,mixed> $blob
     * @param array<string,mixed>|null $meta
     * @return array<string,mixed>
     */
    private function present(array $blob, ?array $meta, int $usageCount, string $host): array
    {
        $tags = [];
        if ($meta !== null && is_string($meta['tags'] ?? null) && $meta['tags'] !== '') {
            $decoded = json_decode($meta['tags'], true);
            $tags = is_array($decoded) ? array_values(array_filter($decoded, 'is_string')) : [];
        }

        return $this->presentRow($blob, $host) + [
            'updated_at' => $blob['updated_at'] ?? null,
            'created_by' => $blob['created_by'] ?? null,
            'alt_text' => $meta['alt_text'] ?? null,
            'caption' => $meta['caption'] ?? null,
            'tags' => $tags,
            'usage_count' => $usageCount,
        ];
    }

    /**
     * Servable URL for the blob: the public `/blobs/{uuid}` route serves public blobs directly;
     * private blobs get a short-lived signed URL so they can be used in <img>.
     *
     * @param array<string,mixed> $blob
     */
    /**
     * Servable URL for a blob, optionally with on-the-fly resize params (width/height/…). Public
     * blobs serve directly; private blobs are signed — and the params are signed IN, so they can't
     * be appended client-side (that would break the signature). glueful/media generates and caches
     * the resized variant on the serve route.
     *
     * @param array<string,mixed>      $blob
     * @param array<string,int|string> $params
     */
    private function serveUrl(array $blob, string $host, array $params = []): string
    {
        $uuid = (string) ($blob['uuid'] ?? '');
        if ($uuid === '') {
            return '';
        }

        // api_prefix() is the same helper RouteManifest registers routes with, so this matches the
        // real /v{n}/blobs/{uuid} route regardless of API-prefix config.
        $base = rtrim($host, '/') . api_prefix($this->context) . '/blobs/' . $uuid;

        if ((string) ($blob['visibility'] ?? 'private') === 'public') {
            return $params === [] ? $base : $base . '?' . http_build_query($params);
        }

        $ttl = (int) config($this->context, 'uploads.signed_urls.ttl', 3600);

        return SignedUrl::make($this->context)->generate($base, $ttl > 0 ? $ttl : 3600, $params);
    }

    /**
     * A width-resized variant URL for images; non-images fall back to the original.
     *
     * @param array<string,mixed> $blob
     */
    private function variantUrl(array $blob, string $host, int $width): string
    {
        if (!str_starts_with((string) ($blob['mime_type'] ?? ''), 'image/')) {
            return $this->serveUrl($blob, $host);
        }

        return $this->serveUrl($blob, $host, ['width' => $width]);
    }

    /** @param array{alt_text:?string,caption:?string,tags:list<string>} $fields */
    private function upsertMeta(string $uuid, array $fields, string $now): void
    {
        $payload = [
            'alt_text' => $fields['alt_text'],
            'caption' => $fields['caption'],
            'tags' => json_encode($fields['tags']),
            'updated_at' => $now,
        ];

        $exists = db($this->context)->table('media_meta')->where('blob_uuid', '=', $uuid)->first();
        if (is_array($exists)) {
            db($this->context)->table('media_meta')->where('blob_uuid', '=', $uuid)->update($payload);
        } else {
            db($this->context)->table('media_meta')->insert(
                $payload + ['blob_uuid' => $uuid, 'created_at' => $now]
            );
        }
    }

    /** @param array<string,mixed> $input */
    private function stringField(array $input, string $key): ?string
    {
        return array_key_exists($key, $input) && is_string($input[$key]) ? trim($input[$key]) : null;
    }

    /**
     * @param array<string,mixed> $input
     * @return list<string>
     */
    private function tagsField(array $input): array
    {
        $tags = $input['tags'] ?? [];
        if (!is_array($tags)) {
            return [];
        }

        $clean = [];
        foreach ($tags as $tag) {
            if (is_string($tag) && trim($tag) !== '') {
                $clean[] = trim($tag);
            }
        }

        return array_values(array_unique($clean));
    }
}

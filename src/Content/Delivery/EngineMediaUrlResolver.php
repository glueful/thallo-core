<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Delivery;

use Glueful\Database\Connection;
use Thallo\Contracts\Delivery\MediaUrlBatchResolver;
use Thallo\Contracts\Delivery\MediaUrlResolver;

/**
 * Blob-route parity resolver (starter-library spec §3): emits a URL ONLY when the
 * framework blob route would serve it anonymously. The access predicate mirrors the
 * FULL route stack — framework/routes/blobs.php attaches `auth` middleware for
 * uploads.access ∈ {true, 'true', 1} BEFORE UploadController::checkBlobAccess()'s
 * looser `!== 'private'` comparison runs — so URL emission and servability never
 * diverge. Scalars are injected by the provider factory; tests construct variants
 * directly (no config reboots).
 *
 * Storefront-v1: `urls()` is the batched form of the SAME predicate (one
 * `IN (...)` query per card list) and `url()` delegates to it, so the single
 * and batched paths can never drift.
 */
final class EngineMediaUrlResolver implements MediaUrlResolver, MediaUrlBatchResolver
{
    /** Batch bound (storefront-v1 spec): the FIRST 100 distinct uuids resolve. */
    private const MAX_BATCH = 100;

    public function __construct(
        private readonly Connection $db,
        /** api_prefix($context) . '/blobs' — host-relative (spec §3). */
        private readonly string $blobUrlBase,
        private readonly bool $uploadsEnabled,
        private readonly mixed $accessMode,
    ) {
    }

    /** The route-stack anonymous-retrieval predicate (spec §3, pinned verbatim). */
    public static function anonymousRetrievalAllowed(mixed $access): bool
    {
        return $access !== 'private'
            && $access !== true
            && $access !== 'true'
            && $access !== 1;
    }

    public function url(string $uuid): ?string
    {
        // Delegation IS the drift guard: one predicate, one code path.
        return $this->urls([$uuid])[$uuid] ?? null;
    }

    public function urls(array $uuids): array
    {
        if (!$this->uploadsEnabled || !self::anonymousRetrievalAllowed($this->accessMode)) {
            return [];
        }

        // First-occurrence dedupe, then the first-100-distinct cap.
        $selected = [];
        foreach ($uuids as $uuid) {
            if (!in_array($uuid, $selected, true)) {
                $selected[] = $uuid;
                if (count($selected) === self::MAX_BATCH) {
                    break;
                }
            }
        }
        if ($selected === []) {
            return [];
        }

        $rows = $this->db->table('blobs')
            ->select(['uuid'])
            ->whereIn('uuid', $selected)
            ->where('visibility', '=', 'public')
            ->where('status', '=', 'active')
            ->whereNull('deleted_at')
            ->get();
        $servable = array_column($rows, 'uuid');

        $base = rtrim($this->blobUrlBase, '/');
        $urls = [];
        foreach ($selected as $uuid) {
            if (in_array($uuid, $servable, true)) {
                $urls[$uuid] = $base . '/' . $uuid;
            }
        }
        return $urls;
    }
}

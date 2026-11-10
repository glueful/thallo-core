<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Delivery;

use Glueful\Database\Connection;
use Thallo\Contracts\Delivery\MediaVariantUrlBatchResolver;
use Thallo\Contracts\Delivery\MediaVariantUrlResolver;

/**
 * Batch variant resolver over the SAME blob-servability query as
 * {@see EngineMediaUrlResolver} (storefront-performance spec §3): one lookup yields the
 * base src, the MIME gate, and every ?width= candidate.
 *
 * `resizingCapable` is the runtime half of the capability gate (the processor binding +
 * `uploads.image_processing.enabled`, computed in the provider factory — a compiled
 * container cannot make REGISTRATION conditional on runtime config): incapable means a
 * valid image degrades to {src, srcset: null} — never fabricated ?width= URLs, and null
 * stays reserved for invalid media, exactly the contract's three outcomes.
 *
 * `webpCapable` (the image processor can encode WebP) adds the same candidates converted to
 * WebP for JPEG and PNG sources: a fraction of the bytes, the original format kept as the
 * <img>'s fallback. A WebP source is WebP already; a GIF may be animated, which conversion
 * would freeze.
 */
final class EngineMediaVariantUrlResolver implements MediaVariantUrlResolver, MediaVariantUrlBatchResolver
{
    /**
     * The raster MIMEs the serving pipeline actually resizes. Source of truth:
     * UploadController::formatFromMime in glueful/framework (the Intervention GD
     * decode set behind /blobs/{uuid}?width=). Any other image/* — svg+xml,
     * unsupported avif, … — must NOT get ?width= candidates: the resize endpoint
     * errors with no fallback-to-original, and browsers do not fall back to src
     * when a chosen srcset candidate fails.
     */
    private const RESIZABLE_MIMES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    /** The sources a WebP copy improves without losing anything. */
    private const WEBP_SOURCES = ['image/jpeg', 'image/png'];

    private const MAX_BATCH = 100;

    public function __construct(
        private readonly Connection $db,
        private readonly string $blobUrlBase,
        private readonly bool $uploadsEnabled,
        private readonly mixed $accessMode,
        private readonly int $maxWidth,
        private readonly bool $resizingCapable,
        private readonly bool $webpCapable = false,
    ) {
    }

    public function variants(string $uuid, array $widths): ?array
    {
        return $this->variantsMany([$uuid], $widths)[$uuid] ?? null;
    }

    public function variantsMany(array $uuids, array $widths): array
    {
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
        $results = array_fill_keys($selected, null);
        $servable = $this->uploadsEnabled && EngineMediaUrlResolver::anonymousRetrievalAllowed($this->accessMode);
        if ($selected === [] || !$servable) {
            return $results;
        }
        $rows = $this->db->table('blobs')
            ->select(['uuid', 'mime_type'])
            ->whereIn('uuid', $selected)
            ->where('visibility', '=', 'public')
            ->where('status', '=', 'active')
            ->whereNull('deleted_at')
            ->get();
        foreach ($rows as $row) {
            $uuid = (string) $row['uuid'];
            $results[$uuid] = $this->result($uuid, (string) ($row['mime_type'] ?? ''), $widths);
        }
        return $results;
    }

    /**
     * @param array<int|string> $widths
     * @return array{src: string, srcset: ?string, webp: ?string}|null
     */
    private function result(string $uuid, string $mimeType, array $widths): ?array
    {
        // Same normalization as UploadController::formatFromMime (lowercase, strip
        // parameters) so this gate matches what the serve pipeline would see.
        $mime = strtolower(trim(explode(';', $mimeType, 2)[0]));
        if (!str_starts_with($mime, 'image/')) {
            return null;
        }

        $src = rtrim($this->blobUrlBase, '/') . '/' . $uuid;
        if (!$this->resizingCapable || !in_array($mime, self::RESIZABLE_MIMES, true)) {
            return ['src' => $src, 'srcset' => null, 'webp' => null];
        }

        $kept = [];
        foreach ($widths as $width) {
            $width = (int) $width;
            if ($width > 0 && $width <= $this->maxWidth) {
                $kept[] = $width;
            }
        }
        if ($kept === []) {
            return ['src' => $src, 'srcset' => null, 'webp' => null];
        }
        $srcset = implode(', ', array_map(static fn (int $w): string => "{$src}?width={$w} {$w}w", $kept));
        $webp = $this->webpCapable && in_array($mime, self::WEBP_SOURCES, true)
            ? implode(', ', array_map(static fn (int $w): string => "{$src}?width={$w}&format=webp {$w}w", $kept))
            : null;
        return ['src' => $src, 'srcset' => $srcset, 'webp' => $webp];
    }
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Delivery;

use Glueful\Database\Connection;
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
 */
final class EngineMediaVariantUrlResolver implements MediaVariantUrlResolver
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

    public function __construct(
        private readonly Connection $db,
        private readonly string $blobUrlBase,
        private readonly bool $uploadsEnabled,
        private readonly mixed $accessMode,
        private readonly int $maxWidth,
        private readonly bool $resizingCapable,
    ) {
    }

    public function variants(string $uuid, array $widths): ?array
    {
        if (!$this->uploadsEnabled || !EngineMediaUrlResolver::anonymousRetrievalAllowed($this->accessMode)) {
            return null;
        }
        $blob = $this->db->table('blobs')
            ->where('uuid', '=', $uuid)
            ->where('visibility', '=', 'public')
            ->where('status', '=', 'active')
            ->whereNull('deleted_at')
            ->first();
        if ($blob === null) {
            return null;
        }
        // Same normalization as UploadController::formatFromMime (lowercase, strip
        // parameters) so this gate matches what the serve pipeline would see.
        $mime = strtolower(trim(explode(';', (string) ($blob['mime_type'] ?? ''), 2)[0]));
        if (!str_starts_with($mime, 'image/')) {
            return null;
        }

        $src = rtrim($this->blobUrlBase, '/') . '/' . $uuid;
        if (!$this->resizingCapable || !in_array($mime, self::RESIZABLE_MIMES, true)) {
            return ['src' => $src, 'srcset' => null];
        }

        $candidates = [];
        foreach ($widths as $width) {
            $width = (int) $width;
            if ($width > 0 && $width <= $this->maxWidth) {
                $candidates[] = "{$src}?width={$width} {$width}w";
            }
        }
        return ['src' => $src, 'srcset' => $candidates === [] ? null : implode(', ', $candidates)];
    }
}

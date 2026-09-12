<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs\Responses\Preview;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only schema holder: the success-envelope `data` payload returned by
 * {@see \Thallo\Core\Content\Http\Controllers\PreviewController::mint()} (HTTP 200).
 * NEVER constructed at runtime — it exists only so the OpenAPI generator can reflect
 * a typed schema for the bare `{token, expires_at, expires_in}` shape emitted
 * directly (no inner wrapper key) when minting a signed preview token.
 * `expires_at` is typed as `\DateTimeInterface` to drive `format: date-time` in the
 * generated schema; the wire value is an ISO-8601 string produced by `date('c')` but
 * the DTO is never instantiated. `theme_url` is the rendered-site preview path
 * (`/_preview/{token}`), or null when the render capability is disabled/absent.
 */
final class PreviewMintData implements ResponseData
{
    public function __construct(
        public readonly string $token,
        public readonly \DateTimeInterface $expires_at,
        public readonly int $expires_in,
        public readonly ?string $theme_url,
    ) {
    }
}

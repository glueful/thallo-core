<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Request body for `POST /v1/admin/entries/{uuid}/preview/{locale}`
 * ({@see \Thallo\Core\Content\Http\Controllers\PreviewController::mint()}).
 *
 * Hydrated by the router (v2). `version_uuid` is optional: an absent value means "mint a token
 * for the current draft"; a present value pins a historical version (existence is validated by
 * the reader at read time).
 */
final class MintPreviewData implements RequestData
{
    public function __construct(
        /** @var string|null UUID of a historical version to pin instead of the current draft. */
        #[Rule('string')]
        public readonly ?string $version_uuid = null,
        /**
         * @var string|null Per-preview theme name, signed into the token; validated via
         *      the render-owned PreviewThemeValidator contract (422 when unknown or
         *      when rendered delivery is unavailable).
         */
        #[Rule('string')]
        public readonly ?string $theme = null,
        /** @var string|null Per-preview accent family (theme-color-config spec §6); enum-validated. */
        #[Rule('string')]
        public readonly ?string $accent = null,
        /** @var string|null Per-preview neutral family; enum-validated. */
        #[Rule('string')]
        public readonly ?string $neutral = null,
    ) {
    }
}

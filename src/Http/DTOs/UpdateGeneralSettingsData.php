<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Request body for `PUT /v1/admin/settings/general`
 * ({@see \Thallo\Core\Http\Controllers\GeneralSettingsController::update()}).
 *
 * Partial update — every field is optional; only non-null fields are written to `.env`. Hydrated +
 * format-validated by the router; cross-field rules (max ≥ default, clamping) stay in the controller.
 */
final class UpdateGeneralSettingsData implements RequestData
{
    public function __construct(
        #[Rule('string')]
        public readonly ?string $site_name = null,
        #[Rule('string')]
        public readonly ?string $site_preview_url = null,
        #[Rule('string')]
        public readonly ?string $default_locale = null,
        #[Rule('numeric')]
        public readonly ?int $default_per_page = null,
        #[Rule('numeric')]
        public readonly ?int $max_per_page = null,
        #[Rule('numeric')]
        public readonly ?int $cache_ttl = null,
        #[Rule('boolean')]
        public readonly ?bool $scheduler_enabled = null,
        #[Rule('boolean')]
        public readonly ?bool $webhooks_enabled = null,
        /** @var bool|null The `thallo.search` capability switch (public search API + reindexing). */
        #[Rule('boolean')]
        public readonly ?bool $search_enabled = null,
        /** Entry uuid rendered at `/`; EXPLICIT '' clears to the env fallback. */
        #[Rule('string')]
        public readonly ?string $homepage_entry = null,
        /** @var string|null Asset uuid of the site logo; '' clears (site name shows instead). */
        #[Rule('string')]
        public readonly ?string $site_logo = null,
        #[Rule('string')]
        public readonly ?string $site_logo_dark = null,
        #[Rule('string')]
        public readonly ?string $site_favicon = null,
        /** @var string|null Live theme name; '' clears to the env/config default. */
        #[Rule('string')]
        public readonly ?string $theme = null,
        /** @var string|null Accent Tailwind family (theme-color-config spec §2); enum-validated in the controller. */
        #[Rule('string')]
        public readonly ?string $theme_accent = null,
        /** @var string|null Neutral Tailwind family; enum-validated in the controller. */
        #[Rule('string')]
        public readonly ?string $theme_neutral = null,
        /** @var string|null Admin SPA base URL for preview-bar deep links; '' clears. */
        #[Rule('string')]
        public readonly ?string $admin_url = null,
        /**
         * @var list<string>|null Content types with public listings/archives;
         *      [] = explicitly none; null = unchanged.
         */
        #[\Glueful\Validation\Attributes\ArrayOf('string')]
        #[Rule('array')]
        public readonly ?array $listing_types = null,
    ) {
    }
}

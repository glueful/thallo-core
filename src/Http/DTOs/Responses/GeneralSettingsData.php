<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs\Responses;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only shape of the instance General settings
 * ({@see \Thallo\Core\Http\Controllers\GeneralSettingsController}). Effective values read from `.env` with
 * the config defaults as fallback.
 */
final class GeneralSettingsData implements ResponseData
{
    public function __construct(
        public readonly string $site_name,
        public readonly string $site_preview_url,
        public readonly string $default_locale,
        public readonly int $default_per_page,
        public readonly int $max_per_page,
        public readonly int $cache_ttl,
        public readonly bool $scheduler_enabled,
        public readonly bool $webhooks_enabled,
        /** The `thallo.search` capability switch (public search API + reindexing). */
        public readonly bool $search_enabled,
        public readonly string $homepage_entry,
        /** Asset uuid of the site logo; '' = unset (site name renders instead). */
        public readonly string $site_logo,
        /** Admin SPA base URL for preview-bar deep links; '' = links hidden. */
        public readonly string $admin_url,
        /** @var list<string> Content types with public listings/archives. */
        #[\Glueful\Validation\Attributes\ArrayOf('string')]
        public readonly array $listing_types,
    ) {
    }
}

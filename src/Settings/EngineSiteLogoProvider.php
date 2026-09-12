<?php

declare(strict_types=1);

namespace Thallo\Core\Settings;

use Thallo\Contracts\Settings\SiteLogoProvider;

/** GeneralSettings-backed site logo (block-library spec §2 + site-identity spec §2). */
final class EngineSiteLogoProvider implements SiteLogoProvider
{
    public function __construct(private readonly GeneralSettings $settings)
    {
    }

    public function siteLogoUuid(string $variant = 'light'): ?string
    {
        // Closed vocabulary (defense in depth under the extension gate):
        // unknown variants are null, never a settings lookup.
        $uuid = match ($variant) {
            'light' => $this->settings->siteLogo(),
            'dark' => $this->settings->siteLogoDark(),
            default => '',
        };
        return $uuid === '' ? null : $uuid;
    }
}

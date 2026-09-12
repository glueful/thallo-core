<?php

declare(strict_types=1);

namespace Thallo\Core\Settings;

use Thallo\Contracts\Settings\SiteFaviconProvider;

/** GeneralSettings-backed favicon (site-identity spec §2). */
final class EngineSiteFaviconProvider implements SiteFaviconProvider
{
    public function __construct(private readonly GeneralSettings $settings)
    {
    }

    public function faviconUuid(): ?string
    {
        $uuid = $this->settings->siteFavicon();
        return $uuid === '' ? null : $uuid;
    }
}

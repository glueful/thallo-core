<?php

declare(strict_types=1);

namespace Thallo\Core\Settings;

use Thallo\Contracts\Settings\SiteNameProvider;

/** GeneralSettings-backed site name: the stored setting, else SITE_NAME, else "Thallo". */
final class EngineSiteNameProvider implements SiteNameProvider
{
    public function __construct(private readonly GeneralSettings $settings)
    {
    }

    public function siteName(): string
    {
        return $this->settings->siteName();
    }
}

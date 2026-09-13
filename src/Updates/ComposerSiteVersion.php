<?php

declare(strict_types=1);

namespace Thallo\Core\Updates;

use Thallo\Contracts\Delivery\SiteVersionProvider;

/** `site.version` from what Composer installed of thallo-core; null in the development checkout. */
final class ComposerSiteVersion implements SiteVersionProvider
{
    public const PACKAGE = 'glueful/thallo-core';

    public function installedVersion(): ?string
    {
        $installed = InstalledVersion::of(self::PACKAGE);

        return $installed['development'] ? null : $installed['version'];
    }
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Updates;

use Composer\InstalledVersions;

/**
 * What Composer installed of a package, as the update notice's "current". A symlinked install
 * path is a Composer path repository — the development checkout, whose placeholder version
 * means nothing — and a package Composer never installed has no version either; both report
 * development, and no notice is ever shown for them.
 */
final class InstalledVersion
{
    /** @return array{version: ?string, development: bool} */
    public static function of(string $package): array
    {
        if (!InstalledVersions::isInstalled($package)) {
            return ['version' => null, 'development' => true];
        }

        $version = InstalledVersions::getPrettyVersion($package);
        $path = InstalledVersions::getInstallPath($package);
        $development = $version === null || $path === null || is_link(rtrim($path, '/'));

        return [
            'version' => $version === null ? null : ltrim($version, 'vV'),
            'development' => $development,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Capabilities;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\PackageManifest;

/**
 * The packages Thallo can't run without (spec §7.6). Core declares them here, beside the code that
 * depends on them: glueful/aegis (InstallRoleGrants, run by provision and setup) and glueful/users
 * (SetupService's UserRepository). They change only with that code. An operator may add more in
 * `thallo.required_packages`; the effective set is the union, so configuration never removes core's.
 */
final class RequiredPackages
{
    public const CORE = ['glueful/aegis', 'glueful/users'];

    public function __construct(private readonly ApplicationContext $context)
    {
    }

    /** @return list<string> core's packages, then the configured additions */
    public function packages(): array
    {
        $configured = config($this->context, 'thallo.required_packages', []);
        $added = is_array($configured) ? array_values(array_filter($configured, 'is_string')) : [];
        return array_values(array_unique([...self::CORE, ...$added]));
    }

    /** @return array<string, string> package => provider class (from the manifest), for the installed ones */
    public function all(): array
    {
        $candidates = (new PackageManifest($this->context))->getCandidates();
        $out = [];
        foreach ($this->packages() as $package) {
            if (isset($candidates[$package])) {
                $out[$package] = $candidates[$package]->provider;
            }
        }
        return $out;
    }

    public function isRequired(string $package): bool
    {
        return in_array($package, $this->packages(), true);
    }
}

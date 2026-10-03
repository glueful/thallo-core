<?php

declare(strict_types=1);

namespace Thallo\Core\Setup;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Core\Capabilities\Activation\ActivationStore;
use Thallo\Core\Capabilities\Activation\EngineActivation;
use Thallo\Core\Capabilities\Console\FreshProcess;
use Thallo\Core\Capabilities\FeatureManagementPolicy;

/**
 * Provision's part in features (feature activation spec §3.9–3.10).
 *
 *  - resumeOpenActivations: a feature left preparing (a deploy-time `--prepare`, an interrupted
 *    turn-on) is finished by `thallo:capabilities:resume` in a child process, so provision's own
 *    process never verifies a boot it changed.
 *  - repairRequiredProviders: a package Thallo requires that is missing from the enabled list is
 *    put back (under the extension-state lock, with the cache rebuilt) when application files are
 *    writable; on a read-only host each one is named with the deploy-time step instead.
 */
final class CapabilityProvisioning
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ActivationStore $store,
        private readonly FeatureManagementPolicy $policy,
        private readonly EngineActivation $engine,
    ) {
    }

    /**
     * @param callable(string): void $line
     * @return int|null the child's exit code, or null when no feature is being turned on
     */
    public function resumeOpenActivations(callable $line): ?int
    {
        $open = array_values(array_filter(
            $this->policy->activationCapabilities(),
            fn (string $id): bool => $this->store->find($id)?->isOpen() === true,
        ));
        if ($open === []) {
            return null;
        }
        $line('Finishing ' . implode(', ', $open) . ' in a fresh process:');
        return (new FreshProcess($this->context))->glueful(['thallo:capabilities:resume'], $line);
    }

    /**
     * @param callable(string): void $line
     * @return list<string> the providers put back
     */
    public function repairRequiredProviders(callable $line): array
    {
        $missing = array_filter(
            $this->policy->requiredProviders(),
            fn (string $provider): bool => !$this->engine->isListed($provider),
        );
        if ($missing === []) {
            return [];
        }
        if (!$this->engine->applicationFilesWritable()) {
            foreach (array_keys($missing) as $package) {
                $line("{$package} is required by Thallo but disabled, and application files are read-only here: "
                    . 'run `php glueful thallo:provision` where they are writable (at deploy time).');
            }
            return [];
        }
        $added = [];
        foreach ($missing as $package => $provider) {
            if ($this->engine->ensureListed($provider)) {
                $added[] = $provider;
                $line("Re-enabled {$package}: Thallo requires it.");
            }
        }
        return $added;
    }
}

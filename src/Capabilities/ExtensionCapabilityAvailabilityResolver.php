<?php

declare(strict_types=1);

namespace Thallo\Core\Capabilities;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\EnabledProviders;
use Glueful\Extensions\PackageManifest;
use Glueful\Extensions\Schema\ReadinessState;
use Glueful\Extensions\Schema\SchemaReadiness;
use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\CapabilityAvailability;
use Thallo\Contracts\Capability\CapabilityAvailabilityResolver;

/**
 * The host's owner-availability answer (spec B3): an ownerless capability is always available;
 * an owned one requires its EXACT Composer package to be installed, its provider enabled, and
 * every one of its migration descriptors Ready (explicit `migrations: none` is trivially ready;
 * divergent outranks pending). Runs during provider boot, so it NEVER throws — a missing ledger
 * or unreachable database during pre-provision boot is an unavailable verdict, not an exception.
 */
class ExtensionCapabilityAvailabilityResolver implements CapabilityAvailabilityResolver
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function resolve(Capability $capability): CapabilityAvailability
    {
        $package = $capability->owningPackage;
        if ($package === null) {
            return CapabilityAvailability::available();
        }

        try {
            $candidate = $this->candidates()[$package] ?? null;
            if ($candidate === null) {
                return CapabilityAvailability::unavailable(
                    "{$package} is not installed.",
                    "composer require {$package}"
                );
            }
            if (!in_array($candidate->provider, $this->enabledProviders(), true)) {
                return CapabilityAvailability::unavailable(
                    "{$package} is installed but not enabled.",
                    "php glueful extensions:enable {$package}"
                );
            }

            $divergent = [];
            $pending = [];
            foreach ($this->packageReadiness($package) as $source => $result) {
                $reasons = array_map(static fn(string $r): string => "{$source}: {$r}", $result['reasons']);
                if ($result['state'] === ReadinessState::Divergent) {
                    $divergent = [...$divergent, ...$reasons];
                } elseif ($result['state'] === ReadinessState::Pending) {
                    $pending = [...$pending, ...$reasons];
                }
            }
            if ($divergent !== []) {
                return CapabilityAvailability::unavailable(
                    "{$package} schema is divergent — " . implode('; ', $divergent),
                    'php glueful migrate:verify'
                );
            }
            if ($pending !== []) {
                return CapabilityAvailability::unavailable(
                    "{$package} schema is pending — " . implode('; ', $pending),
                    'php glueful migrate:run'
                );
            }
            return CapabilityAvailability::available();
        } catch (\Throwable $e) {
            // Pre-provision boot (no ledger, unreachable DB) or any surprise: fail CLOSED with
            // the cause — a capability question must never abort provider boot.
            return CapabilityAvailability::unavailable(
                "{$package} availability could not be determined: {$e->getMessage()}",
                'php glueful migrate:run'
            );
        }
    }

    /**
     * Overridable seam: installed glueful-extension candidates keyed by package name.
     *
     * @return array<string, object{provider: string}>
     */
    protected function candidates(): array
    {
        return (new PackageManifest($this->context))->getCandidates();
    }

    /**
     * Overridable seam: the enabled provider FQCN list.
     *
     * @return list<string>
     */
    protected function enabledProviders(): array
    {
        return EnabledProviders::from($this->context);
    }

    /**
     * Overridable seam: per-source readiness (SchemaReadiness::forPackage; [] = explicit none).
     *
     * @return array<string, array{state: ReadinessState, reasons: list<string>}>
     */
    protected function packageReadiness(string $package): array
    {
        return app($this->context, SchemaReadiness::class)->forPackage($package);
    }
}

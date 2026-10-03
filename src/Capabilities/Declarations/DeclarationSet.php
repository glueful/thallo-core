<?php

declare(strict_types=1);

namespace Thallo\Core\Capabilities\Declarations;

use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\ManagementMode;

/**
 * Every capability declaration, from every source, validated as a whole (spec §7.3). The verdict
 * never depends on the order declarations arrived in: an id declared identically by two sources is
 * one capability; declared differently, it is misconfigured, and nothing picks a winner.
 */
final class DeclarationSet
{
    /** @var array<string, Capability> */
    private array $valid = [];

    /** @var array<string, array{reason: string, packages: list<string>}> */
    private array $misconfigured = [];

    /** @var array<string, true> ids with an invalid declaration (a package entry that can't be read) */
    private array $invalid = [];

    /** @var array<string, Capability> every declared id, valid or not, by its first declaration */
    private array $capabilities = [];

    /**
     * @param list<CapabilityDeclaration> $declarations
     * @param array<string, string> $providers composer package => provider class, from the manifest
     * @param array<string, string|list<array{reason: string, package?: string}>> $errors declared id =>
     *        why its declarations aren't valid (and the packages that declared them, protected with it)
     * @param list<string> $required packages Thallo requires (never an activation engine)
     */
    public function __construct(
        private readonly array $declarations,
        private readonly array $providers,
        array $errors = [],
        array $required = [],
    ) {
        $byId = [];
        foreach ($declarations as $declaration) {
            $byId[$declaration->capability->id][] = $declaration;
        }
        ksort($byId);
        foreach ($byId as $id => $group) {
            $this->capabilities[$id] = $group[0]->capability;
            $distinct = [];
            foreach ($group as $declaration) {
                if (!in_array($declaration->capability, $distinct, false)) {
                    $distinct[] = $declaration->capability;
                }
            }
            if (count($distinct) > 1) {
                $sources = array_map(static fn (CapabilityDeclaration $d): string => $d->source, $group);
                sort($sources);
                $this->misconfigure($id, 'declared differently by ' . implode(' and ', $sources), $group);
                continue;
            }
            $this->valid[$id] = $group[0]->capability;
        }
        $this->rejectEngines($required);
        foreach ($errors as $id => $entries) {
            $id = (string) $id;
            $this->invalid[$id] = true;
            $entries = is_array($entries) ? $entries : [['reason' => $entries]];
            unset($this->valid[$id]);
            // Protected with it: what it already named, the engine any valid declaration of this id
            // owns (an invalid entry under a valid id never frees that engine), and every declarer.
            $packages = $this->misconfigured[$id]['packages'] ?? [];
            foreach ($byId[$id] ?? [] as $declaration) {
                $capability = $declaration->capability;
                if ($capability->management !== ManagementMode::Simple && $capability->owningPackage !== null) {
                    $packages[] = $capability->owningPackage;
                }
            }
            $reasons = [];
            foreach ($entries as $entry) {
                $reasons[] = (string) $entry['reason'];
                if (is_string($entry['package'] ?? null)) {
                    $packages[] = $entry['package'];
                }
            }
            $packages = array_values(array_unique($packages));
            sort($packages);
            $reasons = array_values(array_unique($reasons));
            sort($reasons);
            $this->misconfigured[$id] = ['reason' => implode('; ', $reasons), 'packages' => $packages];
        }
        $this->rejectSharedPackages();
    }

    /** @return list<CapabilityDeclaration> */
    public function declarations(): array
    {
        return $this->declarations;
    }

    /** @return array<string, Capability> valid declarations by id */
    public function valid(): array
    {
        return $this->valid;
    }

    /** @return array<string, array{reason: string, packages: list<string>}> misconfigured capabilities by id */
    public function misconfigured(): array
    {
        return $this->misconfigured;
    }

    /** @return array<string, Capability> every declared id, valid or misconfigured */
    public function capabilities(): array
    {
        return $this->capabilities;
    }

    public function modeOf(string $id): ManagementMode
    {
        return ($this->valid[$id] ?? null)?->management ?? ManagementMode::Simple;
    }

    /** @return array{package: string, provider: string}|null the engine of a valid activation capability */
    public function engineOf(string $id): ?array
    {
        $capability = $this->valid[$id] ?? null;
        if ($capability === null || $capability->management !== ManagementMode::Activation) {
            return null;
        }
        $package = (string) $capability->owningPackage;
        $provider = $this->providers[$package] ?? null;
        return $provider === null ? null : ['package' => $package, 'provider' => $provider];
    }

    /** @return string|null the provider class of a composer package, from the manifest */
    public function providerOf(string $package): ?string
    {
        return $this->providers[$package] ?? null;
    }

    /**
     * The packages a declaration manages, and the packages a misconfigured declaration names.
     *
     * @return array<string, array{class: 'managed'|'misconfigured', capability: string}>
     */
    public function packageManagement(): array
    {
        $out = [];
        foreach ($this->valid as $id => $capability) {
            if ($capability->management !== ManagementMode::Simple && $capability->owningPackage !== null) {
                $out[$capability->owningPackage] = ['class' => 'managed', 'capability' => $id];
            }
        }
        foreach ($this->misconfigured as $id => $entry) {
            foreach ($entry['packages'] as $package) {
                $out[$package] = ['class' => 'misconfigured', 'capability' => $id];
            }
        }
        ksort($out);
        return $out;
    }

    /** An activation engine must be installed, and must not be a package Thallo requires. */
    private function rejectEngines(array $required): void
    {
        foreach ($this->valid as $id => $capability) {
            if ($capability->management !== ManagementMode::Activation) {
                continue;
            }
            $package = (string) $capability->owningPackage;
            if (in_array($package, $required, true)) {
                unset($this->valid[$id]);
                $this->misconfigured[$id] = [
                    'reason' => "an activation over {$package}, which is required by Thallo",
                    'packages' => [],
                ];
            } elseif (!isset($this->providers[$package])) {
                unset($this->valid[$id]);
                $this->misconfigured[$id] = [
                    'reason' => "an activation over {$package}, which is not installed",
                    'packages' => [],
                ];
            }
        }
    }

    /**
     * One package can be managed by one capability only: every capability claiming it is rejected. A
     * misconfigured declaration's packages are claims too, so a conflict over another activation's
     * engine blocks that activation as well; the misconfigured one keeps its own reason.
     */
    private function rejectSharedPackages(): void
    {
        $claims = [];
        foreach ($this->valid as $id => $capability) {
            if ($capability->management !== ManagementMode::Simple && $capability->owningPackage !== null) {
                $claims[$capability->owningPackage][] = $id;
            }
        }
        foreach ($this->misconfigured as $id => $entry) {
            foreach ($entry['packages'] as $package) {
                $claims[$package][] = (string) $id;
            }
        }
        foreach ($claims as $package => $ids) {
            $ids = array_values(array_unique($ids));
            if (count($ids) < 2) {
                continue;
            }
            sort($ids);
            foreach ($ids as $id) {
                if (!isset($this->valid[$id])) {
                    continue;
                }
                unset($this->valid[$id]);
                $others = array_values(array_diff($ids, [$id]));
                $onlyInvalid = array_filter($others, fn (string $other): bool => !isset($this->invalid[$other])) === [];
                $this->misconfigured[$id] = [
                    'reason' => $onlyInvalid
                        ? "a capability over {$package}, which has an invalid capability entry ("
                            . implode(', ', $others) . ')'
                        : "one of several capabilities that claim {$package} (" . implode(', ', $ids) . ')',
                    'packages' => [$package],
                ];
            }
        }
        ksort($this->misconfigured);
    }

    /** @param list<CapabilityDeclaration> $group */
    private function misconfigure(string $id, string $reason, array $group): void
    {
        $packages = [];
        foreach ($group as $declaration) {
            $capability = $declaration->capability;
            if ($capability->management !== ManagementMode::Simple && $capability->owningPackage !== null) {
                $packages[] = $capability->owningPackage;
            }
        }
        $packages = array_values(array_unique($packages));
        sort($packages);
        $this->misconfigured[$id] = ['reason' => $reason, 'packages' => $packages];
    }
}

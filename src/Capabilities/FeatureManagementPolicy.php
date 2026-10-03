<?php

declare(strict_types=1);

namespace Thallo\Core\Capabilities;

use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\ManagementMode;
use Thallo\Core\Capabilities\Declarations\DeclarationSet;

/**
 * Who manages each capability and package (feature activation spec §7.3, §7.6), read from the
 * declarations the contributing packages make and the packages core requires. Nothing here names a
 * capability, a package or a provider class:
 *
 *  - **required:** a package core can't run without (RequiredPackages). No switch.
 *  - **managed:** the engine of an `activation` capability, or the owning package of an
 *    `external_flow` capability. It turns on and off with that capability.
 *  - **misconfigured:** a package named by conflicting declarations; refused everywhere.
 *  - **independent:** everything else, switched with the generic extension toggle.
 *
 * The required and managed providers become `extensions.protected` defaults when the declaration
 * set is built (before any provider boots), so the admin toggle, the framework's extension commands
 * and the protected migration lane all refuse them with the same reason; an operator's own entry
 * for a provider wins.
 */
final class FeatureManagementPolicy
{
    public const REQUIRED = 'required';
    public const MANAGED = 'managed';
    public const MISCONFIGURED = 'misconfigured';
    public const INDEPENDENT = 'independent';

    public function __construct(
        private readonly DeclarationSet $declarations,
        private readonly RequiredPackages $required,
    ) {
    }

    /** @return list<string> valid capability ids that turn on through the activation flow, ascending */
    public function activationCapabilities(): array
    {
        $ids = [];
        foreach ($this->declarations->valid() as $id => $capability) {
            if ($capability->management === ManagementMode::Activation) {
                $ids[] = $id;
            }
        }
        sort($ids);
        return $ids;
    }

    /** How a capability is switched on Extensions › Capabilities: its management mode's value. */
    public function capabilityManagement(string $capability): string
    {
        return $this->declarations->modeOf($capability)->value;
    }

    /** The capability's declaration (valid or not), or null when nothing declares it. */
    public function capability(string $id): ?Capability
    {
        return $this->declarations->capabilities()[$id] ?? null;
    }

    /** Why a declared capability is misconfigured, or null when it isn't. */
    public function misconfiguration(string $id): ?string
    {
        $entry = $this->declarations->misconfigured()[$id] ?? null;
        return $entry === null ? null : self::misconfiguredReason($id, $entry['reason']);
    }

    /** Its name for messages ("Commerce"), or null when it doesn't turn on through activation. */
    public function labelOf(string $capability): ?string
    {
        if ($this->declarations->modeOf($capability) !== ManagementMode::Activation) {
            return null;
        }
        return $this->declarations->valid()[$capability]->label ?? $capability;
    }

    /** @return array<string, string> package => provider, for the packages Thallo can't run without */
    public function requiredProviders(): array
    {
        return $this->required->all();
    }

    /** @return array{package: string, provider: string}|null */
    public function engineOf(string $capability): ?array
    {
        return $this->declarations->engineOf($capability);
    }

    /**
     * How a package is switched, and by whom.
     *
     * @return array{class: string, capability: ?string, reason: ?string, link: ?string}
     */
    public function managementOf(string $package): array
    {
        if ($this->required->isRequired($package)) {
            return ['class' => self::REQUIRED, 'capability' => null, 'reason' => 'Required by Thallo.', 'link' => null];
        }
        $managed = $this->declarations->packageManagement()[$package] ?? null;
        if ($managed === null) {
            return ['class' => self::INDEPENDENT, 'capability' => null, 'reason' => null, 'link' => null];
        }
        $id = $managed['capability'];
        if ($managed['class'] === 'misconfigured') {
            return [
                'class' => self::MISCONFIGURED,
                'capability' => $id,
                'reason' => self::misconfiguredReason($id, $this->declarations->misconfigured()[$id]['reason']),
                'link' => '/extensions',
            ];
        }
        $capability = $this->declarations->valid()[$id];
        $label = $capability->label ?? $id;
        if ($capability->management === ManagementMode::ExternalFlow && $capability->destination !== null) {
            return [
                'class' => self::MANAGED,
                'capability' => $id,
                'reason' => "Managed by {$label}: use {$capability->destination->label}, "
                    . 'not the generic extension toggle.',
                'link' => $capability->destination->path,
            ];
        }
        return [
            'class' => self::MANAGED,
            'capability' => $id,
            'reason' => "Managed by {$label}: turn it on in Extensions, or run "
                . "`php glueful thallo:capabilities:enable {$id}`.",
            'link' => '/extensions',
        ];
    }

    /**
     * The `extensions.protected` defaults: every required, managed and misconfigured provider.
     *
     * @return array<string, array{reason: string, managed_by: string}>
     */
    public function protectedProviders(): array
    {
        $out = [];
        foreach ($this->required->all() as $provider) {
            $out[$provider] = ['reason' => 'Required by Thallo.', 'managed_by' => 'thallo (required)'];
        }
        foreach (array_keys($this->declarations->packageManagement()) as $package) {
            $provider = $this->declarations->providerOf($package);
            if ($provider === null || isset($out[$provider])) {
                continue;
            }
            $management = $this->managementOf($package);
            $out[$provider] = [
                'reason' => (string) $management['reason'],
                'managed_by' => $management['class'] === self::MISCONFIGURED
                    ? 'thallo (misconfigured)'
                    : 'thallo capabilities',
            ];
        }
        return $out;
    }

    private static function misconfiguredReason(string $id, string $why): string
    {
        return "Misconfigured: {$id} is {$why}. Fix the declarations; until then it can't be switched.";
    }
}

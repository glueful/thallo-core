<?php

declare(strict_types=1);

namespace Thallo\Core\Capabilities;

/**
 * Who manages each feature and package (feature activation spec §3.7). Every installed extension
 * package is one of three classes:
 *
 *  - **required**: Thallo references it without a `has()` / `class_exists()` guard on a path every
 *    install runs, so switching it off breaks the site. glueful/aegis (InstallRoleGrants, run by
 *    provision and setup) and glueful/users (SetupService injects its UserRepository).
 *  - **managed**: it backs a feature, which owns turning it on and off. glueful/commerce
 *    (thallo.commerce) and glueful/subscriptions (thallo.subscriptions) through the activation
 *    flow; glueful/tenancy through Settings › Workspaces.
 *  - **independent**: everything else, switched with the generic extension toggle or
 *    `extensions:enable`. That includes glueful/i18n and glueful/audit (CoreServiceProvider guards
 *    both with `has()`), glueful/media (only its admin controller), glueful/email-notification
 *    (only the commerce mail pack), glueful/import-export (its importers capability follows the
 *    engine), glueful/payvia and glueful/meilisearch.
 *
 * The required and managed providers are merged into `extensions.protected` as defaults, so the
 * admin toggle, the framework's extension commands and the protected migration lane all refuse
 * them with the same reason; an operator's own entry for a provider wins.
 */
final class FeatureManagementPolicy
{
    public const REQUIRED = 'required';
    public const MANAGED = 'managed';
    public const INDEPENDENT = 'independent';

    /** Capability id => its engine: the composer package and its provider. Sorted by capability. */
    private const ENGINES = [
        'thallo.commerce' => [
            'package' => 'glueful/commerce',
            'provider' => 'Glueful\\Extensions\\Commerce\\CommerceServiceProvider',
            'label' => 'Commerce',
        ],
        'thallo.subscriptions' => [
            'package' => 'glueful/subscriptions',
            'provider' => 'Glueful\\Extensions\\Subscriptions\\SubscriptionsServiceProvider',
            'label' => 'Subscriptions',
        ],
    ];

    /** Package => provider, for the packages Thallo can't run without. */
    private const REQUIRED_PACKAGES = [
        'glueful/aegis' => 'Glueful\\Extensions\\Aegis\\Services\\AegisServiceProvider',
        'glueful/users' => 'Glueful\\Extensions\\Users\\UsersServiceProvider',
    ];

    private const TENANCY_PACKAGE = 'glueful/tenancy';
    private const TENANCY_CAPABILITY = 'thallo.tenancy';
    private const TENANCY_PROVIDER = 'Glueful\\Extensions\\Tenancy\\TenancyServiceProvider';
    private const TENANCY_REASON = 'Workspace enforcement is managed by the tenancy enablement flow — '
        . 'use Settings › Workspaces, not the generic extension toggle.';

    /** @return list<string> capability ids that turn on through the activation flow, ascending */
    public function activationCapabilities(): array
    {
        $ids = array_keys(self::ENGINES);
        sort($ids);
        return $ids;
    }

    /**
     * How a capability is switched on the Features page: `activation` (the activation flow),
     * `workspaces` (Settings › Workspaces) or `simple` (a plain switch).
     */
    public function capabilityManagement(string $capability): string
    {
        if (isset(self::ENGINES[$capability])) {
            return 'activation';
        }
        return $capability === self::TENANCY_CAPABILITY ? 'workspaces' : 'simple';
    }

    /** The feature's name for messages ("Commerce"), or null when it has no activation flow. */
    public function labelOf(string $capability): ?string
    {
        return self::ENGINES[$capability]['label'] ?? null;
    }

    /** @return array<string, class-string> package => provider, for the packages Thallo can't run without */
    public function requiredProviders(): array
    {
        return self::REQUIRED_PACKAGES;
    }

    /** @return array{package: string, provider: class-string}|null */
    public function engineOf(string $capability): ?array
    {
        $engine = self::ENGINES[$capability] ?? null;
        return $engine === null ? null : ['package' => $engine['package'], 'provider' => $engine['provider']];
    }

    /**
     * How a package is switched, and by whom.
     *
     * @return array{class: string, capability: ?string, reason: ?string, link: ?string}
     */
    public function managementOf(string $package): array
    {
        if (isset(self::REQUIRED_PACKAGES[$package])) {
            return ['class' => self::REQUIRED, 'capability' => null, 'reason' => 'Required by Thallo.', 'link' => null];
        }
        foreach (self::ENGINES as $capability => $engine) {
            if ($engine['package'] === $package) {
                return [
                    'class' => self::MANAGED,
                    'capability' => $capability,
                    'reason' => self::managedReason($engine['label'], $capability),
                    'link' => '/features',
                ];
            }
        }
        if ($package === self::TENANCY_PACKAGE) {
            return [
                'class' => self::MANAGED,
                'capability' => null,
                'reason' => self::TENANCY_REASON,
                'link' => '/settings/workspaces',
            ];
        }
        return ['class' => self::INDEPENDENT, 'capability' => null, 'reason' => null, 'link' => null];
    }

    /**
     * The `extensions.protected` defaults: every required and managed provider.
     *
     * @return array<class-string, array{reason: string, managed_by: string}>
     */
    public function protectedProviders(): array
    {
        $out = [];
        foreach (self::REQUIRED_PACKAGES as $provider) {
            $out[$provider] = ['reason' => 'Required by Thallo.', 'managed_by' => 'thallo (required)'];
        }
        foreach (self::ENGINES as $capability => $engine) {
            $out[$engine['provider']] = [
                'reason' => self::managedReason($engine['label'], $capability),
                'managed_by' => 'thallo features',
            ];
        }
        $out[self::TENANCY_PROVIDER] = ['reason' => self::TENANCY_REASON, 'managed_by' => 'glueful/tenancy enablement'];
        return $out;
    }

    private static function managedReason(string $label, string $capability): string
    {
        return "Managed by {$label}: turn it on in Features, or run "
            . "`php glueful thallo:features:enable {$capability}`.";
    }
}

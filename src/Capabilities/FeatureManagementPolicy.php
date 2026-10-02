<?php

declare(strict_types=1);

namespace Thallo\Core\Capabilities;

/**
 * Who manages each feature and package (feature activation spec §3.7). This first slice names the
 * capabilities that turn on through the activation flow and their engines; the package management
 * classes (required, managed, independent) and their protection follow.
 */
final class FeatureManagementPolicy
{
    /** Capability id => its engine: the composer package and its provider. Sorted by capability. */
    private const ENGINES = [
        'thallo.commerce' => [
            'package' => 'glueful/commerce',
            'provider' => 'Glueful\\Extensions\\Commerce\\CommerceServiceProvider',
        ],
        'thallo.subscriptions' => [
            'package' => 'glueful/subscriptions',
            'provider' => 'Glueful\\Extensions\\Subscriptions\\SubscriptionsServiceProvider',
        ],
    ];

    /** @return list<string> capability ids that turn on through the activation flow, ascending */
    public function activationCapabilities(): array
    {
        $ids = array_keys(self::ENGINES);
        sort($ids);
        return $ids;
    }

    /** @return array{package: string, provider: class-string}|null */
    public function engineOf(string $capability): ?array
    {
        return self::ENGINES[$capability] ?? null;
    }
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Starter\Kinds;

use Thallo\Core\Content\Starter\AbstractStarterKind;
use Thallo\Core\Content\Starter\Fingerprint;
use Thallo\Core\Content\Starter\SeedContext;
use Thallo\Core\Content\Starter\StarterApplyResult;
use Thallo\Core\Content\Starter\StarterDefinition;
use Thallo\Core\Settings\SettingsStore;

final class SettingKind extends AbstractStarterKind
{
    public function __construct(private readonly SettingsStore $settings)
    {
    }

    public function kind(): string
    {
        return 'setting';
    }

    public function definitions(): array
    {
        return [
            new StarterDefinition('setting:site_name', 'site_name', ['source' => 'tenant_name']),
            new StarterDefinition('setting:default_locale', 'default_locale', ['source' => 'default_locale']),
            new StarterDefinition('setting:listing_types', 'listing_types', ['value' => 'post']),
        ];
    }

    public function locateExact(string $definitionKey): ?array
    {
        $value = $this->settings->get($definitionKey);
        return $value === null ? null : [
            'key' => $definitionKey,
            'fingerprint' => Fingerprint::of(['key' => $definitionKey, 'value' => $value]),
        ];
    }

    public function apply(StarterDefinition $definition, SeedContext $seed): StarterApplyResult
    {
        if ($this->settings->get($definition->definitionKey) !== null) {
            return StarterApplyResult::SkippedCollision;
        }
        $value = match ($definition->definitionKey) {
            'site_name' => $seed->tenantName,
            'default_locale' => $seed->defaultLocale,
            default => (string) ($definition->payload['value'] ?? ''),
        };
        $this->settings->putMany([$definition->definitionKey => $value]);
        return StarterApplyResult::Applied;
    }

    public function updateTo(
        StarterDefinition $definition,
        string $rowKey,
        SeedContext $seed,
    ): void {
        // Settings are seed-only and never reach the sync engine.
    }

    public function rename(StarterDefinition $definition, string $oldKey): void
    {
        throw new \LogicException('Starter settings are seed-only.');
    }

    public function syncable(): bool
    {
        return false;
    }
}

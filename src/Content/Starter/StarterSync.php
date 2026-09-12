<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Starter;

use Thallo\Core\Settings\SettingsStore;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Thallo\Tenancy\Contracts\TenantStarterSync;

final class StarterSync implements TenantStarterSync
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly TenantContextRunner $tenants,
        private readonly TenantAdministration $administration,
        private readonly StarterDefinitions $definitions,
        private readonly StarterProvenanceRepository $provenance,
        private readonly StarterTransaction $transaction,
        private readonly SettingsStore $settings,
    ) {
    }

    public function sync(string $tenantUuid, ?string $kind = null): array
    {
        $tenant = $this->administration->getTenant($this->context, $tenantUuid);
        if ($tenant === null || $tenant['status'] !== 'active') {
            throw new \DomainException('Starter sync requires an active tenant.');
        }
        $report = new SyncReport();
        $this->tenants->runAsTenant($tenantUuid, function () use ($tenant, $kind, $report): void {
            $this->settings->clearCache();
            $seed = new SeedContext(
                (string) $tenant['uuid'],
                (string) $tenant['name'],
                $this->settings->get('default_locale') ?? 'en',
                null,
            );
            foreach ($this->definitions->syncKinds() as $starterKind) {
                if ($kind !== null && $starterKind->kind() !== $kind) {
                    continue;
                }
                $this->transaction->run(fn() => $this->syncKind($starterKind, $seed, $report));
            }
        });
        return $report->items();
    }

    public function syncAll(?string $kind = null): array
    {
        $reports = [];
        $this->tenants->forEachTenant(function (string $tenantUuid) use ($kind, &$reports): void {
            $reports[$tenantUuid] = $this->sync($tenantUuid, $kind);
        });
        return $reports;
    }

    private function syncKind(StarterKind $kind, SeedContext $seed, SyncReport $report): void
    {
        $encountered = [];
        foreach ($kind->definitions() as $definition) {
            $encountered[$definition->sourceId] = true;
            $sourceFingerprint = $kind->fingerprint($definition);
            $provenance = $this->provenance->findBySource($kind->kind(), $definition->sourceId);
            if ($provenance === null) {
                $this->adoptOrAdd($kind, $definition, $seed, $sourceFingerprint, $report);
                continue;
            }
            $located = $kind->locateExact((string) $provenance['definition_key']);
            if ($located === null || $located['fingerprint'] !== $provenance['fingerprint']) {
                $this->provenance->markState((string) $provenance['uuid'], 'customized');
                $report->add($kind->kind(), $definition->sourceId, 'skipped_customized');
                continue;
            }
            $action = $provenance['state'] === 'applied' ? 'unchanged' : 'rejoined_applied';
            if ($definition->definitionKey !== $provenance['definition_key']) {
                if ($kind->locateExact($definition->definitionKey) !== null) {
                    $report->add($kind->kind(), $definition->sourceId, 'skipped_rename_collision');
                    continue;
                }
                $kind->rename($definition, (string) $provenance['definition_key']);
                $action = 'renamed';
            }
            if ($located['fingerprint'] !== $sourceFingerprint) {
                $kind->updateTo($definition, $definition->definitionKey, $seed);
                $action = $action === 'renamed' ? 'renamed' : 'updated';
            }
            $this->provenance->recordApplied(
                $kind->kind(),
                $definition->definitionKey,
                $definition->sourceId,
                $sourceFingerprint,
            );
            $report->add($kind->kind(), $definition->sourceId, $action);
        }
        foreach ($this->provenance->sourceIdsFor($kind->kind()) as $row) {
            if (!isset($encountered[(string) $row['source_id']])) {
                $this->provenance->markState((string) $row['uuid'], 'orphaned_source');
                $report->add($kind->kind(), (string) $row['source_id'], 'orphaned_source');
            }
        }
    }

    private function adoptOrAdd(
        StarterKind $kind,
        StarterDefinition $definition,
        SeedContext $seed,
        string $sourceFingerprint,
        SyncReport $report,
    ): void {
        $located = $kind->locateForAdoption($definition);
        if ($located === null) {
            if ($kind->apply($definition, $seed) === StarterApplyResult::Applied) {
                $this->provenance->recordApplied(
                    $kind->kind(),
                    $definition->definitionKey,
                    $definition->sourceId,
                    $sourceFingerprint,
                );
                $report->add($kind->kind(), $definition->sourceId, 'added');
            }
            return;
        }
        $this->provenance->recordApplied(
            $kind->kind(),
            (string) $located['key'],
            $definition->sourceId,
            $sourceFingerprint,
        );
        $provenance = $this->provenance->findBySource($kind->kind(), $definition->sourceId);
        if ($located['fingerprint'] !== $sourceFingerprint) {
            $this->provenance->markState((string) $provenance['uuid'], 'customized');
                $report->add($kind->kind(), $definition->sourceId, 'adopted_customized');
            return;
        }
        if ($located['key'] !== $definition->definitionKey) {
            if ($kind->locateExact($definition->definitionKey) !== null) {
                $this->provenance->markState((string) $provenance['uuid'], 'customized');
                $report->add($kind->kind(), $definition->sourceId, 'skipped_rename_collision');
                return;
            }
            $kind->rename($definition, (string) $located['key']);
            $this->provenance->renameKey((string) $provenance['uuid'], $definition->definitionKey);
        }
        $report->add($kind->kind(), $definition->sourceId, 'adopted_applied');
    }
}

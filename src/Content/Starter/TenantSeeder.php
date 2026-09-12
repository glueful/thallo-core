<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Starter;

use Thallo\Core\Settings\GeneralSettings;
use Thallo\Core\Settings\SettingsStore;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Extensions\Contracts\Tenancy\TenantProvisioningRunner;
use Thallo\Tenancy\Contracts\TenantSeedActivator;
use Thallo\Tenancy\Contracts\TenantSeedRepair;
use Thallo\Tenancy\StarterSeedException;

final class TenantSeeder implements TenantSeedActivator, TenantSeedRepair
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly TenantProvisioningRunner $tenants,
        private readonly TenantAdministration $administration,
        private readonly StarterDefinitions $definitions,
        private readonly StarterProvenanceRepository $provenance,
        private readonly StarterTransaction $transaction,
        private readonly GeneralSettings $settings,
        private readonly SettingsStore $store,
        private readonly ?StarterSeedFailpoint $failpoint = null,
    ) {
    }

    public function seedAndActivate(string $tenantUuid, string $ownerUserUuid): void
    {
        $this->seed($tenantUuid, $ownerUserUuid);
    }

    public function repair(string $tenantUuid): void
    {
        $tenant = $this->tenant($tenantUuid);
        if (!in_array($tenant['status'], ['provisioning', 'active'], true)) {
            throw new \DomainException('Only provisioning or active tenants may be seeded.');
        }
        $owner = null;
        foreach ($this->administration->listMembers($this->context, $tenantUuid) as $member) {
            if (($member['status'] ?? null) === 'active' && ($member['role'] ?? null) === 'owner') {
                $owner = (string) $member['user_uuid'];
                break;
            }
        }
        if ($owner === null) {
            throw new \DomainException('Tenant seed repair requires an active owner.');
        }
        $this->seed($tenantUuid, $owner);
    }

    private function seed(string $tenantUuid, string $ownerUserUuid): void
    {
        $tenant = $this->tenant($tenantUuid);
        if (!in_array($tenant['status'], ['provisioning', 'active'], true)) {
            throw new \DomainException('Only provisioning or active tenants may be seeded.');
        }
        $this->tenants->runAsProvisioningTenant($tenantUuid, function () use ($tenant, $ownerUserUuid): void {
            $this->store->clearCache();
            $this->transaction->run(function () use ($tenant, $ownerUserUuid): void {
                $seed = new SeedContext(
                    (string) $tenant['uuid'],
                    (string) $tenant['name'],
                    $this->settings->defaultLocale(),
                    $ownerUserUuid,
                );
                foreach ($this->definitions->kinds() as $kind) {
                    foreach ($kind->definitions() as $definition) {
                        if ($this->provenance->findBySource($kind->kind(), $definition->sourceId) !== null) {
                            continue;
                        }
                        try {
                            $result = $kind->apply($definition, $seed);
                            if ($result === StarterApplyResult::Applied) {
                                $this->provenance->recordApplied(
                                    $kind->kind(),
                                    $definition->definitionKey,
                                    $definition->sourceId,
                                    $kind->fingerprint($definition),
                                );
                            }
                        } catch (\Throwable $e) {
                            throw new StarterSeedException($kind->kind() . ':' . $definition->sourceId, $e);
                        }
                    }
                }
                if ($tenant['status'] === 'provisioning') {
                    $this->administration->markActive($this->context, (string) $tenant['uuid']);
                }
                $this->failpoint?->afterMarkActive((string) $tenant['uuid']);
            });
        });
    }

    /** @return array{uuid:string,slug:string,name:string,status:string} */
    private function tenant(string $tenantUuid): array
    {
        return $this->administration->getTenant($this->context, $tenantUuid)
            ?? throw new \InvalidArgumentException("Unknown tenant {$tenantUuid}.");
    }
}

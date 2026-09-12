<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Authorization;

use Glueful\Database\Connection;
use Thallo\Contracts\Tenancy\TenancyLifecycleAudit;
use Thallo\Contracts\Tenancy\WriteBarrier;

final class TenantRolePolicyMutator
{
    public function __construct(
        private readonly Connection $connection,
        private readonly TenantRoleOverrideRepository $overrides,
        private readonly EffectiveRoleEvaluator $evaluator,
        private readonly TenancyLifecycleAudit $audit,
        private readonly WriteBarrier $barrier,
    ) {
    }

    /**
     * @param list<string> $grants
     * @param list<string> $revokes
     * @return array{version:int,set:list<array{capability:string,effect:string}>,cleared:list<array{capability:string,effect:string}>,effective:list<string>}
     */
    public function reconcile(
        string $tenantUuid,
        string $roleSlug,
        array $grants,
        array $revokes,
        ?string $actorUuid,
    ): array {
        return $this->barrier->runWritable(fn () => $this->connection->transaction(function () use (
            $tenantUuid,
            $roleSlug,
            $grants,
            $revokes,
            $actorUuid,
        ): array {
            $change = $this->overrides->reconcileRoleOverridesInTransaction(
                $tenantUuid,
                $roleSlug,
                $grants,
                $revokes,
                $actorUuid,
            );
            $effective = $this->evaluator->capabilitiesForUncached($tenantUuid, $roleSlug);
            $this->connection->afterCommit(function () use ($change, $tenantUuid, $roleSlug, $actorUuid): void {
                foreach ($change['set'] as $row) {
                    $this->audit->record('tenant.role_override_set', $actorUuid, $tenantUuid, [
                        'role_slug' => $roleSlug,
                        'capability' => $row['capability'],
                        'effect' => $row['effect'],
                    ]);
                }
                foreach ($change['cleared'] as $row) {
                    $this->audit->record('tenant.role_override_cleared', $actorUuid, $tenantUuid, [
                        'role_slug' => $roleSlug,
                        'capability' => $row['capability'],
                        'effect' => $row['effect'],
                    ]);
                }
            });
            return $change + ['effective' => $effective];
        }));
    }

    /** @return array{version:int,cleared:list<array{role_slug:string,capability:string,effect:string}>} */
    public function reset(string $tenantUuid, ?string $actorUuid): array
    {
        return $this->barrier->runWritable(fn () => $this->connection->transaction(
            function () use ($tenantUuid, $actorUuid): array {
            $change = $this->overrides->clearTenantOverridesInTransaction($tenantUuid);
            $this->connection->afterCommit(fn () => $this->audit->record(
                'tenant.roles_reset',
                $actorUuid,
                $tenantUuid,
                ['cleared' => count($change['cleared'])],
            ));
            return $change;
            }
        ));
    }
}

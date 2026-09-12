<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Scheduling;

use Thallo\Core\Content\Enums\ScheduleAction;
use Thallo\Core\Content\Enums\ScheduleStatus;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Repositories\ScheduleRepository;
use Thallo\Core\Content\Services\PublishService;
use Thallo\Core\Settings\GeneralSettings;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Thallo\Contracts\Tenancy\WriteBarrier;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Fires due scheduled publish/unpublish actions through the normal publish path.
 *
 * The durable claim and terminal outcome writes are separate from the action itself:
 * claimDuePending() commits pending -> processing first, PublishService owns its own
 * transaction, and markOutcome() writes done/failed/canceled afterwards.
 *
 * SYSTEM PATH: claim/reclaim/markOutcome drain entry_schedules cross-tenant (raw PDO, the named
 * bypass); each drained row's tenant_uuid is carried into the publish so its builder writes scope
 * correctly. When tenancy is active a row missing tenant_uuid fails closed (never an unscoped publish).
 */
final class ScheduleRunner
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ScheduleRepository $schedules,
        private readonly PublishService $publisher,
        private readonly EntryRepository $entries,
        private readonly SystemFlags $flags,
        private readonly ?TenantContextRunner $tenants = null,
        private readonly ?WriteBarrier $barrier = null,
    ) {
    }

    public function run(int $limit = 100): int
    {
        // The run itself is the proof that the scheduler is ticking (Health's "scheduler" check),
        // whether or not scheduled publishing is switched on.
        (new SchedulerHeartbeat($this->flags))->beat();

        if (!app($this->context, GeneralSettings::class)->schedulerEnabled()) {
            return 0;
        }

        // Refuse to drain while a retrofit is in progress (fresh persisted read → catches an
        // already-running scheduler when the barrier rises mid-flight).
        $this->barrier?->assertWritable();

        $this->schedules->reclaimStale(300);

        // Per-run lease token: rows this run claims are stamped with it, and only this run can write
        // their terminal outcome. If a slow batch overruns the reclaim window and another run takes
        // a row over, our markOutcome for it no-ops instead of racing that run's result.
        $lockToken = bin2hex(random_bytes(16));

        $fired = 0;
        foreach ($this->schedules->claimDuePending($limit, $lockToken) as $row) {
            $tenantUuid = isset($row['tenant_uuid']) ? (string) $row['tenant_uuid'] : '';
            [$status, $reason] = $this->fireScoped($row, $tenantUuid);
            $this->schedules->markOutcome((int) $row['id'], $status, $reason, $lockToken);
            $fired++;
        }

        return $fired;
    }

    /**
     * Run the per-row action in the row's tenant context when tenancy is active, so PublishService's
     * builder writes scope correctly. Fail closed if the runner is bound but the row carries no
     * tenant — the claim SELECT must return tenant_uuid; a missing one is a scoping bug, and running
     * the publish unscoped would write the '' partition.
     *
     * @param array<string,mixed> $row
     * @return array{0:ScheduleStatus,1:?string}
     */
    private function fireScoped(array $row, string $tenantUuid): array
    {
        if ($this->flags->enforcementActive()) {
            if ($tenantUuid === '') {
                return [ScheduleStatus::Failed, 'schedule row missing tenant_uuid under active tenancy'];
            }
            if ($this->tenants === null) {
                return [ScheduleStatus::Failed, 'tenant runner unavailable under active tenancy'];
            }
            return $this->tenants->runAsTenant($tenantUuid, fn (): array => $this->fire($row));
        }

        return $this->fire($row);
    }

    /**
     * @param array<string,mixed> $row
     * @return array{0:ScheduleStatus,1:?string}
     */
    private function fire(array $row): array
    {
        $entry = $this->entries->findEntry((string) $row['entry_uuid']);
        if ($entry === null || ($entry['status'] ?? null) === 'deleted') {
            return [ScheduleStatus::Canceled, 'target entry no longer exists'];
        }

        try {
            if ($row['action'] === ScheduleAction::Publish->value) {
                $actor = ((string) ($row['created_by'] ?? '')) ?: null;
                $this->publisher->publish((string) $row['entry_uuid'], (string) $row['locale'], $actor);
            } else {
                $this->publisher->unpublish((string) $row['entry_uuid'], (string) $row['locale']);
            }

            return [ScheduleStatus::Done, null];
        } catch (\Throwable $e) {
            return [ScheduleStatus::Failed, $e->getMessage()];
        }
    }
}

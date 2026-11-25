<?php

declare(strict_types=1);

namespace Thallo\Core\Content\DocsSync;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantScope;
use Glueful\Helpers\Utils;
use Glueful\Queue\QueueManager;
use Glueful\Storage\StorageManager;
use Thallo\Core\Content\Jobs\DocsSyncJob;

/**
 * Starting, recovering and pruning docs sync runs (docs sync design §6.1, §6.4, §4). A run holds the
 * workspace's single slot from its reservation until it finishes; recovery re-dispatches a run
 * whose delivery was lost, within the sync's own budget of attempts, and never touches one whose
 * lease is live. Every change to a run here starts with the slot fence (§6.0).
 */
final class DocsSyncService
{
    public const LEASE_SECONDS = 120;
    public const REDISPATCH_SECONDS = 120;
    public const ATTEMPT_BUDGET = 5;

    private ?\Closure $beforeReserve = null;

    public function __construct(
        private readonly Connection $db,
        private readonly DocsSyncStore $store,
        private readonly DocsSyncRunRepository $runs,
        private readonly ?QueueManager $queue = null,
        private readonly ?ApplicationContext $context = null,
        private readonly ?CurrentTenantResolver $tenants = null,
        private readonly ?StorageManager $storage = null,
    ) {
    }

    /**
     * Reserve the slot and dispatch the run (§6.1).
     *
     * @return array<string,mixed> the run
     * @throws DocsSyncConflict no source, a run already holds the slot, or the source just changed
     */
    public function start(string $mode, ?string $actor): array
    {
        if (!in_array($mode, ['commit', 'dry_run'], true)) {
            throw new \InvalidArgumentException('mode: commit (Sync now) or dry_run (Preview).');
        }
        $state = $this->store->state();
        if ($state['source'] === null) {
            throw DocsSyncConflict::noSource();
        }
        if ($state['active_run'] !== null) {
            $this->recover(); // §6.4: a start refused because of a stuck run recovers it
            throw DocsSyncConflict::running();
        }
        $id = Utils::generateNanoID(12);
        $workspace = TenantScope::current($this->tenants, $this->context);
        if ($this->beforeReserve !== null) {
            $hook = $this->beforeReserve;
            $this->beforeReserve = null;
            $hook();
        }

        return $this->db->transaction(function () use ($id, $mode, $actor, $state, $workspace): array {
            $this->runs->create([
                'id' => $id,
                'revision' => $state['revision'],
                'source' => json_encode($state['source']?->toArray(), JSON_THROW_ON_ERROR),
                'mode' => $mode,
                'status' => 'reserved',
                'workspace' => $workspace,
                'created_by' => $actor,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            if (!$this->store->reserve($id, $state['revision'])) {
                throw $this->store->state()['active_run'] !== null
                    ? DocsSyncConflict::running()
                    : DocsSyncConflict::sourceChanged();
            }
            $this->dispatch($id, $workspace);
            return (array) $this->runs->find($id);
        });
    }

    /**
     * Concurrency proofs only: runs once, after the state is read and before the reservation, the
     * window in which the source can change under a start. Production code never calls it.
     */
    public function beforeNextReserve(\Closure $fn): void
    {
        $this->beforeReserve = $fn;
    }

    /** Recover the workspace's run holding the slot, if it needs it (§6.4). Never throws for one run. */
    public function recover(): void
    {
        $run = $this->store->state()['active_run'];
        if ($run === null) {
            return;
        }
        $this->db->transaction(function () use ($run): void {
            if (!$this->store->slotFence($run)) {
                return; // it finished meanwhile
            }
            $row = $this->runs->find($run);
            if ($row === null || $row['status'] === 'finished') {
                $this->store->release($run); // a slot left behind by a run that no longer needs it
                return;
            }
            if ($this->leaseLive()) {
                return; // a live attempt, the last one included, is never cut off
            }
            if ((bool) $row['cancel_requested']) {
                // Cancel was asked and no attempt is alive to honour it: cancelled now, not
                // re-dispatched only to stop at its first boundary.
                $this->runs->finish($run, 'cancelled');
                $this->store->release($run);
                return;
            }
            if ((int) $row['attempts'] >= self::ATTEMPT_BUDGET) {
                $this->runs->finishSpent($run, $row);
                $this->store->release($run);
                return;
            }
            $since = self::secondsSince(is_string($row['last_dispatch_at'] ?? null)
                ? $row['last_dispatch_at']
                : (string) $row['created_at']);
            if ($since >= self::REDISPATCH_SECONDS) {
                $this->dispatch($run, is_string($row['workspace'] ?? null) ? $row['workspace'] : null);
            }
        });
    }

    /**
     * Cancel a run (§6.5). The slot fence takes the state row first, so Cancel and the run's own
     * finish are ordered by its lock: a run that already finished answers so, with its outcome. A
     * run with a live attempt is flagged, and that attempt stops after the page in flight; one with
     * no live attempt is finished as cancelled here.
     *
     * @return array{finished: bool, outcome: ?string}
     */
    public function cancel(string $run): array
    {
        return $this->db->transaction(function () use ($run): array {
            if (!$this->store->slotFence($run)) {
                $row = $this->runs->find($run);
                return ['finished' => true, 'outcome' => is_array($row) ? ($row['outcome'] ?? null) : null];
            }
            $this->runs->update($run, ['cancel_requested' => true]);
            if ($this->leaseLive()) {
                return ['finished' => false, 'outcome' => null];
            }
            $this->runs->finish($run, 'cancelled');
            $this->store->release($run);
            return ['finished' => true, 'outcome' => 'cancelled'];
        });
    }

    /**
     * Release a run whose attempt stopped reporting in, or that was never claimed (§6.5): it is
     * finished as interrupted, keeping what it wrote. Refused while its lease is live. It waits for a
     * page in flight (the slot fence), and the old attempt is fenced out at its next write.
     *
     * @return array{released: bool}
     */
    public function release(string $run): array
    {
        return $this->db->transaction(function () use ($run): array {
            if (!$this->store->slotFence($run) || !$this->releasable($run)) {
                return ['released' => false];
            }
            $this->runs->finish($run, 'interrupted', 'Released: the sync had stopped reporting in.');
            $this->store->release($run);
            return ['released' => true];
        });
    }

    /**
     * Whether the run holding the slot may be released (§6.5, §9): its worker stopped reporting in
     * (a lease over 120 s old), or no worker has claimed it in the two minutes since it was started.
     * A sync that was just queued is waiting, not stuck.
     */
    public function releasable(string $run): bool
    {
        $state = $this->store->state();
        if ($state['active_run'] !== $run) {
            return false;
        }
        if ($state['lease_token'] !== null) {
            return self::secondsSince($state['lease_at']) >= self::LEASE_SECONDS;
        }
        $row = $this->runs->find($run);
        if ($row === null) {
            return true;
        }
        // Never claimed: counted from when it was started, not from its last re-dispatch, which
        // recovery renews on every read of the card while no worker takes the queue.
        return self::secondsSince((string) $row['created_at']) >= self::REDISPATCH_SECONDS;
    }

    /** Whether the workspace's lease is live; read under a fence. */
    private function leaseLive(): bool
    {
        $state = $this->store->state();
        return $state['lease_token'] !== null && self::secondsSince($state['lease_at']) < self::LEASE_SECONDS;
    }

    /**
     * Prune the workspace's runs (§4): archives of runs finished over a day ago, and runs (with their
     * pages) finished before the retention, except the last sync, whose history is kept.
     *
     * @return array{runs: int, files: int}
     */
    public function prune(): array
    {
        $disk = $this->storage()->disk('uploads');
        $files = 0;
        foreach ($this->runs->finishedBefore(gmdate('Y-m-d H:i:s', time() - 86400)) as $id) {
            if ($disk->directoryExists('docs-sync/' . $id)) {
                $disk->deleteDirectory('docs-sync/' . $id);
                $files++;
            }
        }
        $days = (int) ($this->context !== null ? config($this->context, 'import_export.retention_days', 30) : 30);
        $synced = $this->store->state()['synced_run'];
        $old = array_values(array_filter(
            $this->runs->finishedBefore(gmdate('Y-m-d H:i:s', time() - max(1, $days) * 86400)),
            static fn (string $id): bool => $id !== $synced,
        ));
        $this->runs->delete($old);

        return ['runs' => count($old), 'files' => $files];
    }

    /**
     * Push the run's job. On the database queue sharing this connection's handle (PostgreSQL,
     * MySQL) the push is part of the caller's transaction, so a run is reserved and queued or
     * neither. Otherwise (Redis, or SQLite, whose queue has its own handle) it follows the commit,
     * and recovery covers a push that never happened.
     */
    private function dispatch(string $run, ?string $workspace): void
    {
        $push = function () use ($run, $workspace): void {
            if ($this->queue === null) {
                return; // no queue: recovery has nothing to push either; the run waits
            }
            $this->queue->push(DocsSyncJob::class, DocsSyncJob::payload($run, $workspace), $this->queueName());
            $this->runs->markDispatched($run);
        };
        if ($this->atomicQueue()) {
            $push();
            return;
        }
        $this->db->afterCommit($push);
    }

    private function atomicQueue(): bool
    {
        if ($this->context === null) {
            return false;
        }
        $connection = (string) config($this->context, 'queue.default', 'database');
        $driver = (string) config($this->context, "queue.connections.{$connection}.driver", $connection);
        return $driver === 'database' && $this->db->getDriverName() !== 'sqlite';
    }

    private function queueName(): string
    {
        $queue = $this->context !== null ? config($this->context, 'import_export.queue', 'import-export') : null;
        return is_string($queue) && $queue !== '' ? $queue : 'import-export';
    }

    private function storage(): StorageManager
    {
        if ($this->storage !== null) {
            return $this->storage;
        }
        if ($this->context === null) {
            throw new \LogicException('Pruning needs the storage manager.');
        }
        return $this->context->getContainer()->get(StorageManager::class);
    }

    /** Seconds since a UTC 'Y-m-d H:i:s' timestamp; a missing one is long ago. */
    public static function secondsSince(?string $utc): int
    {
        if ($utc === null || $utc === '') {
            return PHP_INT_MAX;
        }
        $at = strtotime(substr($utc, 0, 19) . ' UTC');
        return $at === false ? PHP_INT_MAX : time() - $at;
    }
}

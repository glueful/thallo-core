<?php

declare(strict_types=1);

namespace Thallo\Core\Capabilities\Activation;

use Glueful\Database\Connection;
use Thallo\Core\Capabilities\CapabilityStateStore;

/**
 * The activation store (feature activation spec §3.3). One capability_activations row per
 * capability is the coordination point: every method runs in a transaction that first locks that
 * row, so starting a generation, joining it, superseding it and a runner's writes are serialized.
 *
 * The capability's switch is only ever written here or under a fence: starting a new generation
 * publishes it off, superseding publishes it off, and a runner publishes it on only inside
 * withinFenced(). A runner's writes are fenced on generation, lease-owner token and a live lease,
 * so a runner whose lease was taken over, or whose generation was superseded, changes nothing.
 */
final class ActivationStore
{
    public const LEASE_SECONDS = 120;

    public function __construct(
        private readonly Connection $db,
        private readonly CapabilityStateStore $states,
    ) {
    }

    /**
     * Starts a generation, or joins the open one. Starting also completes MARK_PREPARING and
     * publishes the capability off — in this one transaction.
     */
    public function startOrJoin(string $capability, string $actor): ActivationRecord
    {
        return $this->db->transaction(function () use ($capability, $actor): ActivationRecord {
            $row = $this->lockRow($capability);
            if (in_array($row['status'], ActivationStatus::OPEN, true)) {
                $this->event($capability, (int) $row['generation'], 'joined', [], $actor);
                return ActivationRecord::fromRow($row);
            }
            $generation = (int) $row['generation'] + 1;
            $this->db->getPDO()->prepare(
                "UPDATE capability_activations SET generation = ?, status = ?, steps_done = ?::jsonb,
                   failed_step = NULL, error = NULL, remedy = NULL, owner_token = NULL,
                   lease_expires_at = NULL, workspaces = '{}'::jsonb, result = '{}'::jsonb, actor = ?,
                   started_at = NOW(), updated_at = NOW()
                 WHERE capability = ?"
            )->execute([
                $generation,
                ActivationStatus::PREPARING,
                (string) json_encode([ActivationStep::MARK_PREPARING]),
                $actor,
                $capability,
            ]);
            $this->states->put($capability, false);
            $this->event($capability, $generation, 'started', [], $actor);
            return $this->mustFind($capability);
        });
    }

    public function find(string $capability): ?ActivationRecord
    {
        $stmt = $this->db->getPDO()->prepare('SELECT * FROM capability_activations WHERE capability = ?');
        $stmt->execute([$capability]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row === false ? null : ActivationRecord::fromRow($row);
    }

    /**
     * Takes the lease when it is free or expired. Null when another runner holds a live lease;
     * throws ActivationSuperseded when $generation is not the current, open one.
     */
    public function acquire(string $capability, int $generation): ?ActivationLease
    {
        return $this->db->transaction(function () use ($capability, $generation): ?ActivationLease {
            $row = $this->lockRow($capability);
            if ((int) $row['generation'] !== $generation || !in_array($row['status'], ActivationStatus::OPEN, true)) {
                throw new ActivationSuperseded("Activation {$capability} #{$generation} is no longer current.");
            }
            $token = bin2hex(random_bytes(16));
            $stmt = $this->db->getPDO()->prepare(
                'UPDATE capability_activations
                    SET owner_token = ?, lease_expires_at = NOW() + make_interval(secs => ?), updated_at = NOW()
                  WHERE capability = ? AND generation = ?
                    AND (owner_token IS NULL OR lease_expires_at IS NULL OR lease_expires_at < NOW())'
            );
            $stmt->execute([$token, self::LEASE_SECONDS, $capability, $generation]);
            return $stmt->rowCount() === 1 ? new ActivationLease($capability, $generation, $token) : null;
        });
    }

    /**
     * Runs $fn in one transaction holding the row lock, after checking the fence (generation,
     * owner token, live lease), then extends the lease. Throws ActivationSuperseded before $fn runs
     * when the fence fails.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function withinFenced(ActivationLease $lease, callable $fn): mixed
    {
        return $this->db->transaction(function () use ($lease, $fn): mixed {
            $this->assertFence($lease);
            $result = $fn();
            $this->extendLease($lease);
            return $result;
        });
    }

    /**
     * Records a step done (idempotent), merging $resultPatch into the result. FINALIZE closes the
     * operation as succeeded; any other step leaves (or puts back) the operation preparing.
     *
     * @param array<string, mixed> $resultPatch
     */
    public function completeStep(ActivationLease $lease, string $step, array $resultPatch = []): ActivationRecord
    {
        return $this->withinFenced($lease, function () use ($lease, $step, $resultPatch): ActivationRecord {
            $status = $step === ActivationStep::FINALIZE ? ActivationStatus::SUCCEEDED : ActivationStatus::PREPARING;
            $this->db->getPDO()->prepare(
                "UPDATE capability_activations SET
                   steps_done = CASE WHEN steps_done @> jsonb_build_array(?::text) THEN steps_done
                                     ELSE steps_done || jsonb_build_array(?::text) END,
                   result = result || ?::jsonb, status = ?, failed_step = NULL, error = NULL, remedy = NULL,
                   updated_at = NOW()
                 WHERE capability = ?"
            )->execute([$step, $step, (string) json_encode((object) $resultPatch), $status, $lease->capability]);
            $this->event($lease->capability, $lease->generation, 'step_done', ['step' => $step], '');
            if ($status === ActivationStatus::SUCCEEDED) {
                $this->event($lease->capability, $lease->generation, 'succeeded', [], '');
            }
            return $this->mustFind($lease->capability);
        });
    }

    public function failStep(ActivationLease $lease, string $step, string $error, ?string $remedy): ActivationRecord
    {
        return $this->withinFenced($lease, function () use ($lease, $step, $error, $remedy): ActivationRecord {
            $this->db->getPDO()->prepare(
                'UPDATE capability_activations
                    SET status = ?, failed_step = ?, error = ?, remedy = ?, updated_at = NOW()
                  WHERE capability = ?'
            )->execute([ActivationStatus::FAILED, $step, $error, $remedy, $lease->capability]);
            $detail = ['step' => $step, 'error' => $error];
            $this->event($lease->capability, $lease->generation, 'step_failed', $detail, '');
            return $this->mustFind($lease->capability);
        });
    }

    /** Records a workspace's readiness (ready|failed), or drops it (null). Fenced. */
    public function markWorkspace(ActivationLease $lease, string $tenantUuid, ?string $state): void
    {
        $this->withinFenced($lease, function () use ($lease, $tenantUuid, $state): void {
            $sql = $state === null
                ? 'UPDATE capability_activations SET workspaces = workspaces - ?::text, updated_at = NOW()
                     WHERE capability = ?'
                : 'UPDATE capability_activations SET workspaces = workspaces || jsonb_build_object(?::text, ?::text),
                     updated_at = NOW() WHERE capability = ?';
            $params = $state === null ? [$tenantUuid, $lease->capability] : [$tenantUuid, $state, $lease->capability];
            $this->db->getPDO()->prepare($sql)->execute($params);
        });
    }

    /**
     * Turns the capability off: generation+1, status superseded, lease cleared, the capability
     * published off — one transaction. With $expectedGeneration (a cancel), a generation that is no
     * longer current, or a closed one, throws ActivationSuperseded and changes nothing.
     */
    public function supersede(string $capability, string $actor, ?int $expectedGeneration = null): int
    {
        return $this->db->transaction(function () use ($capability, $actor, $expectedGeneration): int {
            $row = $this->lockRow($capability);
            if (
                $expectedGeneration !== null
                && ((int) $row['generation'] !== $expectedGeneration
                    || !in_array($row['status'], ActivationStatus::OPEN, true))
            ) {
                throw new ActivationSuperseded(
                    "Activation {$capability} #{$expectedGeneration} is no longer current; a newer decision exists."
                );
            }
            $generation = (int) $row['generation'] + 1;
            $this->db->getPDO()->prepare(
                'UPDATE capability_activations SET generation = ?, status = ?, owner_token = NULL,
                   lease_expires_at = NULL, actor = ?, updated_at = NOW()
                 WHERE capability = ?'
            )->execute([$generation, ActivationStatus::SUPERSEDED, $actor, $capability]);
            $this->states->put($capability, false);
            $this->event($capability, $generation, 'superseded', [], $actor);
            return $generation;
        });
    }

    /** Gives the lease back, when it is still this runner's. Never throws for a stale lease. */
    public function release(ActivationLease $lease): void
    {
        $this->db->transaction(function () use ($lease): void {
            $this->lockRow($lease->capability);
            $this->db->getPDO()->prepare(
                'UPDATE capability_activations SET owner_token = NULL, lease_expires_at = NULL, updated_at = NOW()
                 WHERE capability = ? AND generation = ? AND owner_token = ?'
            )->execute([$lease->capability, $lease->generation, $lease->ownerToken]);
        });
    }

    /**
     * Workspace creation: FOR SHARE on every activation row, in capability order, inside the
     * caller's transaction (held to its commit). Returns each capability's fresh state.
     *
     * @return array<string, array{on: bool, preparing: bool}>
     */
    public function shareAll(): array
    {
        if (!$this->db->withinTransaction()) {
            throw new \LogicException('shareAll() must run inside the workspace seed transaction.');
        }
        $rows = $this->db->getPDO()
            ->query('SELECT capability, status FROM capability_activations ORDER BY capability FOR SHARE')
            ->fetchAll(\PDO::FETCH_ASSOC);
        $states = [];
        foreach ($rows as $row) {
            $capability = (string) $row['capability'];
            $states[$capability] = [
                'on' => $this->states->fresh($capability) === true,
                'preparing' => in_array($row['status'], ActivationStatus::OPEN, true),
            ];
        }
        return $states;
    }

    /** @return array<string, mixed> the locked row (created idle when missing) */
    private function lockRow(string $capability): array
    {
        $pdo = $this->db->getPDO();
        $pdo->prepare(
            "INSERT INTO capability_activations (capability, status) VALUES (?, 'idle')"
            . ' ON CONFLICT (capability) DO NOTHING'
        )->execute([$capability]);
        $stmt = $pdo->prepare('SELECT * FROM capability_activations WHERE capability = ? FOR UPDATE');
        $stmt->execute([$capability]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \RuntimeException("Activation row for {$capability} could not be locked.");
        }
        return $row;
    }

    private function assertFence(ActivationLease $lease): void
    {
        $stmt = $this->db->getPDO()->prepare(
            'SELECT generation, owner_token, (lease_expires_at IS NOT NULL AND lease_expires_at >= NOW()) AS live
               FROM capability_activations WHERE capability = ? FOR UPDATE'
        );
        $stmt->execute([$lease->capability]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (
            $row === false
            || (int) $row['generation'] !== $lease->generation
            || (string) ($row['owner_token'] ?? '') !== $lease->ownerToken
            || !(bool) $row['live']
        ) {
            throw new ActivationSuperseded(
                "Activation {$lease->capability} #{$lease->generation}: this runner no longer holds it."
            );
        }
    }

    private function extendLease(ActivationLease $lease): void
    {
        $stmt = $this->db->getPDO()->prepare(
            'UPDATE capability_activations SET lease_expires_at = NOW() + make_interval(secs => ?)
              WHERE capability = ? AND generation = ? AND owner_token = ?'
        );
        $stmt->execute([self::LEASE_SECONDS, $lease->capability, $lease->generation, $lease->ownerToken]);
        if ($stmt->rowCount() !== 1) {
            throw new ActivationSuperseded(
                "Activation {$lease->capability} #{$lease->generation}: this runner no longer holds it."
            );
        }
    }

    private function mustFind(string $capability): ActivationRecord
    {
        return $this->find($capability) ?? throw new \RuntimeException("No activation row for {$capability}.");
    }

    /** @param array<string, mixed> $detail */
    private function event(string $capability, int $generation, string $event, array $detail, string $actor): void
    {
        $this->db->getPDO()->prepare(
            'INSERT INTO capability_activation_events (capability, generation, event, detail, actor)
             VALUES (?, ?, ?, ?::jsonb, ?)'
        )->execute([$capability, $generation, $event, (string) json_encode((object) $detail), $actor]);
    }
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Content\DocsSync;

use Glueful\Database\Connection;

/**
 * The workspace's docs sync state (docs sync design §4, §6.0): one row holding the source, its
 * revision, the run slot and the lease. Every write to a run after it is reserved starts with one
 * of the two fences here, as its transaction's first write: the update locks the row until the
 * transaction ends (a row lock on PostgreSQL and MySQL; SQLite's write lock is the database), so
 * every change to a run is ordered by it. Writes go through the query builder, which scopes them
 * to the current workspace like every other builder write.
 */
final class DocsSyncStore
{
    /** The statuses a run may move to each one from: forward only (§6.0). */
    private const FROM = [
        'dispatched' => ['reserved'],
        'running' => ['reserved', 'dispatched', 'running'],
        'finished' => ['reserved', 'dispatched', 'running'],
    ];

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return array{source: ?DocsSyncSource, revision: int, active_run: ?string, lease_token: ?string,
     *     lease_at: ?string, synced_run: ?string}
     */
    public function state(): array
    {
        $this->ensureRow();
        $row = (array) $this->db->table('docs_sync_state')->where('site', '=', 'site')->first();
        $source = is_string($row['source'] ?? null) ? json_decode($row['source'], true) : null;

        return [
            'source' => is_array($source) ? DocsSyncSource::fromArray($source) : null,
            'revision' => (int) ($row['revision'] ?? 0),
            'active_run' => self::str($row['active_run'] ?? null),
            'lease_token' => self::str($row['lease_token'] ?? null),
            'lease_at' => self::str($row['lease_at'] ?? null),
            'synced_run' => self::str($row['synced_run'] ?? null),
        ];
    }

    /** Save the source as a new revision; refused while a run holds the slot (§4). */
    public function saveSource(DocsSyncSource $source): int
    {
        return $this->writeSource(json_encode($source->toArray(), JSON_THROW_ON_ERROR));
    }

    /** Remove the source, as a new revision; refused while a run holds the slot. Pages stay. */
    public function removeSource(): int
    {
        return $this->writeSource(null);
    }

    /**
     * Take the free slot for `$run`, only at `$revision` (§6.1): false when another run holds it or
     * the source moved to another revision since it was read.
     */
    public function reserve(string $run, int $revision): bool
    {
        $this->ensureRow();
        return $this->db->table('docs_sync_state')
            ->where('site', '=', 'site')
            ->whereNull('active_run')
            ->where('revision', '=', $revision)
            ->update([
                'active_run' => $run, 'lease_token' => null, 'lease_at' => null,
                'touch' => self::touch(), 'updated_at' => self::now(),
            ]) === 1;
    }

    /** Free the slot `$run` holds, with its lease. The caller holds a fence. */
    public function release(string $run): void
    {
        $this->db->table('docs_sync_state')
            ->where('site', '=', 'site')
            ->where('active_run', '=', $run)
            ->update([
                'active_run' => null, 'lease_token' => null, 'lease_at' => null,
                'touch' => self::touch(), 'updated_at' => self::now(),
            ]);
    }

    /** Record `$run` as the last sync that landed. The caller holds the owner's fence. */
    public function markSynced(string $run): void
    {
        $this->db->table('docs_sync_state')->where('site', '=', 'site')->update(['synced_run' => $run]);
    }

    /**
     * Claim the lease of `$run` for a new attempt (§6.2): true when the run holds the slot and its
     * lease is free or older than `$expiry` seconds. Run inside a transaction: the slot fence locks
     * the row first, so the lease is read and taken under that lock.
     */
    public function claim(string $run, string $token, int $expiry): bool
    {
        if (!$this->slotFence($run)) {
            return false;
        }
        $state = $this->state();
        $live = $state['lease_token'] !== null
            && DocsSyncService::secondsSince($state['lease_at']) < $expiry;
        if ($live) {
            return false;
        }
        return $this->db->table('docs_sync_state')
            ->where('site', '=', 'site')
            ->where('active_run', '=', $run)
            ->update(['lease_token' => $token, 'lease_at' => self::now(), 'touch' => self::touch()]) === 1;
    }

    /** The owner's fence: true when `$run` holds the slot and `$token` the lease. Renews the lease. */
    public function ownerFence(string $run, string $token): bool
    {
        return $this->db->table('docs_sync_state')
            ->where('site', '=', 'site')
            ->where('active_run', '=', $run)
            ->where('lease_token', '=', $token)
            ->update(['lease_at' => self::now(), 'touch' => self::touch()]) === 1;
    }

    /** The slot fence: true when `$run` still holds the slot (an outside action on it). */
    public function slotFence(string $run): bool
    {
        return $this->db->table('docs_sync_state')
            ->where('site', '=', 'site')
            ->where('active_run', '=', $run)
            ->update(['updated_at' => self::now(), 'touch' => self::touch()]) === 1;
    }

    /** Move a run's status forward; false (and nothing changed) when that would move it back. */
    public function advanceStatus(string $run, string $to): bool
    {
        $from = self::FROM[$to] ?? throw new \InvalidArgumentException("Unknown run status \"{$to}\".");

        return $this->db->table('docs_sync_runs')
            ->where('id', '=', $run)
            ->whereIn('status', $from)
            ->update(['status' => $to]) === 1;
    }

    private function writeSource(?string $json): int
    {
        return $this->db->transaction(function () use ($json): int {
            $this->ensureRow();
            $row = $this->db->table('docs_sync_state')->where('site', '=', 'site')->first();
            $revision = (int) ($row['revision'] ?? 0);
            $updated = $this->db->table('docs_sync_state')
                ->where('site', '=', 'site')
                ->whereNull('active_run')
                ->where('revision', '=', $revision)
                ->update([
                    'source' => $json,
                    'revision' => $revision + 1,
                    'synced_run' => null,
                    'touch' => self::touch(),
                    'updated_at' => self::now(),
                ]);
            if ($updated !== 1) {
                throw DocsSyncConflict::running();
            }
            return $revision + 1;
        });
    }

    /**
     * Creates the workspace's row when missing, in its own transaction (a savepoint inside an open
     * one), so a duplicate-key loss to a concurrent first writer never aborts the caller's.
     */
    private function ensureRow(): void
    {
        if ($this->db->table('docs_sync_state')->select(['id'])->first() !== null) {
            return;
        }
        try {
            $this->db->transaction(function (): void {
                $this->db->table('docs_sync_state')->insert([
                    'site' => 'site', 'revision' => 0, 'updated_at' => self::now(), 'touch' => self::touch(),
                ]);
            });
        } catch (\Throwable $e) {
            if ($this->db->table('docs_sync_state')->select(['id'])->first() === null) {
                throw $e;
            }
        }
    }

    private static function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    private static function touch(): string
    {
        return bin2hex(random_bytes(16));
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}

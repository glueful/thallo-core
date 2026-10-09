<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Palette;

use Glueful\Database\Connection;
use Thallo\Contracts\Style\PaletteProvider;
use Thallo\Core\Settings\GeneralSettings;

/**
 * The workspace's palette state (custom palette spec §4.3): one row whose generation moves with
 * every palette mutation. lock() is an UPDATE-as-lock through the query builder — scoped like every
 * builder write — so it serialises with every other holder until the transaction ends. Every path
 * that takes it takes it first (docs/internal/palette-lock-order.md).
 */
final class PaletteState
{
    private ?int $heldAtLevel = null;
    private ?\Closure $afterSnapshot = null;

    public function __construct(
        private readonly Connection $db,
        private readonly PaletteProvider $palettes,
        private readonly PaletteJobRepository $jobs,
        private readonly ?GeneralSettings $settings = null,
    ) {
    }

    /**
     * Creates the workspace's row when missing. The insert runs in its own transaction — inside an
     * open one, a SAVEPOINT (Connection::transaction nests) — so a duplicate-key loss to a concurrent
     * first writer rolls back only the savepoint and never leaves the caller's transaction aborted.
     */
    public function ensureRow(): void
    {
        if ($this->db->table('palette_state')->select(['id'])->first() !== null) {
            return;
        }
        try {
            $this->db->transaction(function (): void {
                $this->db->table('palette_state')->insert([
                    'site' => 'site', 'generation' => 0, 'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
            });
        } catch (\Throwable $e) {
            if ($this->db->table('palette_state')->select(['id'])->first() === null) {
                throw $e; // not the race: surface it
            }
        }
    }

    /** An unlocked read: what a writer normalises against before its fenced write. */
    public function snapshot(): PaletteSnapshot
    {
        $this->ensureRow();
        $snapshot = $this->read();
        if ($this->afterSnapshot !== null) {
            $fn = $this->afterSnapshot;
            $this->afterSnapshot = null;
            $fn(); // a concurrency proof commits its palette mutation here, between read and write
        }
        return $snapshot;
    }

    /**
     * Concurrency proofs only (custom palette plan ruling 15): runs once, right after the next
     * unlocked snapshot is read — the window between a writer's normalisation and its fenced write.
     * Production code never calls it.
     */
    public function afterNextSnapshot(\Closure $fn): void
    {
        $this->afterSnapshot = $fn;
    }

    /** Takes the row (inside a transaction) and reads the state it now holds. */
    public function lock(): PaletteSnapshot
    {
        if (!$this->db->withinTransaction()) {
            throw new \LogicException('PaletteState::lock() needs an open transaction');
        }
        $this->ensureRow();
        $this->db->table('palette_state')->where('site', '=', 'site')
            ->update(['updated_at' => gmdate('Y-m-d H:i:s')]);
        if ($this->heldAtLevel === null) {
            $this->heldAtLevel = $this->db->transactionLevel();
            $release = function (): void {
                $this->heldAtLevel = null;
            };
            $this->db->afterCommit($release);
            $this->db->afterRollback($release);
        }
        return $this->read();
    }

    /** Inside a held lock: generation + 1, returned. */
    public function bump(): int
    {
        if (!$this->heldInThisTransaction()) {
            throw new \LogicException('PaletteState::bump() needs the palette lock');
        }
        $current = $this->generationNow();
        $this->db->table('palette_state')->where('site', '=', 'site')
            ->update(['generation' => $current + 1, 'updated_at' => gmdate('Y-m-d H:i:s')]);
        return $current + 1;
    }

    public function heldInThisTransaction(): bool
    {
        return $this->heldAtLevel !== null && $this->db->withinTransaction();
    }

    /** A plain unlocked read of the generation. */
    public function generationNow(): int
    {
        $row = $this->db->table('palette_state')->select(['generation'])->first();
        return (int) ($row['generation'] ?? 0);
    }

    private function read(): PaletteSnapshot
    {
        // Settings are memoised per process: a snapshot taken under the lock must see another
        // request's committed palette change.
        $this->settings?->clearStoreCache();
        return new PaletteSnapshot($this->generationNow(), $this->palettes->palette(), $this->jobs->active());
    }
}

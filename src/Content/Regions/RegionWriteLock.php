<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Regions;

use Glueful\Database\Connection;

/**
 * The one lock every writer of the `regions` table takes (regions-stage spec §4.5): a
 * transaction-scoped PostgreSQL advisory lock on a fixed key, held until the transaction that
 * took it ends. It is an advisory lock and not `SELECT … FOR UPDATE` because a region with no row
 * yet cannot be row-locked. The key carries no tenant segment: every writer must compute the same
 * one, and some are built without the tenant context.
 */
final class RegionWriteLock
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function key(): int
    {
        return crc32('thallo:regions') & 0x7FFFFFFF;
    }

    /**
     * Run `$fn` holding the region lock. Opens a transaction when none is open; inside an open one
     * it still acquires — an open transaction is not a held lock. PostgreSQL re-grants the lock to
     * the session that holds it and releases it when the outermost transaction ends.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function within(callable $fn): mixed
    {
        $locked = function () use ($fn): mixed {
            $stmt = $this->db->getPDO()->prepare('SELECT pg_advisory_xact_lock(?)');
            $stmt->execute([$this->key()]);
            return $fn();
        };
        return $this->db->withinTransaction() ? $locked() : $this->db->transaction($locked);
    }

    /** Whether this connection holds the region lock now (a granted lock in pg_locks). */
    public function isHeld(): bool
    {
        $stmt = $this->db->getPDO()->prepare(
            "SELECT EXISTS (SELECT 1 FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()"
            . ' AND granted AND classid = 0 AND objid = ? AND objsubid = 1)'
        );
        $stmt->execute([$this->key()]);
        return (bool) $stmt->fetchColumn();
    }
}

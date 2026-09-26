<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Layouts;

use Glueful\Database\Connection;

/**
 * The locks every writer of a layout takes (type layouts spec §5.5, §5.7): transaction-scoped
 * PostgreSQL advisory locks, one per layout and one per content type, held until the outermost
 * transaction ends. Advisory, not `SELECT … FOR UPDATE`, because a layout with no row yet cannot
 * be row-locked. The keys carry no tenant segment, as the region lock's do not: every writer —
 * background block migrations included — must compute the same one.
 *
 * Lock order everywhere: the type lock (`withinType`) before the layout lock (`within`).
 *
 * Returning from `within` means committed only when `within` opened the transaction; inside a
 * caller's outer transaction it does not. So writers never change preview state, caches or purges
 * after `within` returns: they register such effects with `Connection::afterCommit()` inside the
 * callback, and those run after the outermost commit and are discarded on rollback.
 */
final class LayoutWriteLock
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function key(string $surface, string $target): int
    {
        return crc32("thallo:layouts:{$surface}:{$target}") & 0x7FFFFFFF;
    }

    public function typeKey(string $typeSlug): int
    {
        return crc32("thallo:layouts:type:{$typeSlug}") & 0x7FFFFFFF;
    }

    /**
     * Run `$fn` holding one layout's lock. Opens a transaction when none is open (committing when
     * `$fn` returns, rolling back when it throws); inside an open one it still acquires — an open
     * transaction is not a held lock.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function within(string $surface, string $target, callable $fn): mixed
    {
        return $this->locked($this->key($surface, $target), $fn);
    }

    /**
     * Run `$fn` holding a content type's lock: taken by a layout save or removal on the `entry`
     * surface before its layout lock, and by a content-type migration of that type, so a save
     * cannot bind a field the migration is deleting.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function withinType(string $typeSlug, callable $fn): mixed
    {
        return $this->locked($this->typeKey($typeSlug), $fn);
    }

    public function isHeld(string $surface, string $target): bool
    {
        return $this->held($this->key($surface, $target));
    }

    public function isTypeHeld(string $typeSlug): bool
    {
        return $this->held($this->typeKey($typeSlug));
    }

    /**
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function locked(int $key, callable $fn): mixed
    {
        $run = function () use ($key, $fn): mixed {
            $stmt = $this->db->getPDO()->prepare('SELECT pg_advisory_xact_lock(?)');
            $stmt->execute([$key]);
            return $fn();
        };
        return $this->db->withinTransaction() ? $run() : $this->db->transaction($run);
    }

    private function held(int $key): bool
    {
        $stmt = $this->db->getPDO()->prepare(
            "SELECT EXISTS (SELECT 1 FROM pg_locks WHERE locktype = 'advisory' AND pid = pg_backend_pid()"
            . ' AND granted AND classid = 0 AND objid = ? AND objsubid = 1)'
        );
        $stmt->execute([$key]);
        return (bool) $stmt->fetchColumn();
    }
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Capabilities\Activation;

use Glueful\Database\Connection;

/**
 * A block insert that may lose a race, made safe inside a larger transaction. PostgreSQL aborts the
 * whole transaction on a failed statement, so the insert runs under a savepoint: losing the race on
 * the block's slug (uniq_block_type_slug) rolls back to the savepoint and counts as "already there";
 * any other failure rolls back to the savepoint and is rethrown. The enclosing transaction stays
 * usable either way.
 */
final class BlockInsert
{
    private const SAVEPOINT = 'thallo_block_seed';
    private const SLUG_CONSTRAINT = 'uniq_block_type_slug';

    /** @return bool true when inserted, false when a block with that slug already exists */
    public static function ifAbsent(Connection $db, callable $insert): bool
    {
        if (!$db->withinTransaction()) {
            return (bool) $db->transaction(static fn (): bool => self::ifAbsent($db, $insert));
        }
        $pdo = $db->getPDO();
        $pdo->exec('SAVEPOINT ' . self::SAVEPOINT);
        try {
            $insert();
        } catch (\Throwable $e) {
            $pdo->exec('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT);
            if (self::isSlugConflict($e)) {
                return false;
            }
            throw $e;
        }
        $pdo->exec('RELEASE SAVEPOINT ' . self::SAVEPOINT);
        return true;
    }

    private static function isSlugConflict(\Throwable $e): bool
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if (
                ($cause instanceof \PDOException && (string) $cause->getCode() === '23505'
                    || str_contains($cause->getMessage(), 'SQLSTATE[23505]'))
                && str_contains($cause->getMessage(), self::SLUG_CONSTRAINT)
            ) {
                return true;
            }
        }
        return false;
    }
}

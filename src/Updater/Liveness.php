<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

/**
 * When a running operation is dead (spec §5; plan ruling 5): its run lock can be taken and its
 * process group has no member left. Either alone is not proof: a descendant can close the inherited
 * lock, and a group can outlive a lock.
 */
final class Liveness
{
    public static function groupAlive(?int $pgid): bool
    {
        if ($pgid === null || $pgid <= 1 || !function_exists('posix_kill')) {
            return false;
        }
        if (posix_kill(-$pgid, 0)) {
            return true;
        }
        return posix_get_last_error() === 1; // EPERM: members exist that we may not signal
    }

    /** @return resource|null */
    public static function claimIfDead(array $op, Paths $paths)
    {
        if (($op['status'] ?? null) !== Status::RUNNING) {
            return null;
        }
        $lock = FileLock::exclusive($paths->runLock(), false);
        if ($lock === null) {
            return null;
        }
        if (self::groupAlive(isset($op['pgid']) ? (int) $op['pgid'] : null)) {
            FileLock::release($lock);
            return null;
        }
        return $lock;
    }
}

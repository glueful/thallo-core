<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

final class Status
{
    public const REQUESTED = 'requested';
    public const RUNNING = 'running';
    public const SUCCEEDED = 'succeeded';
    public const FAILED = 'failed';
    public const ROLLED_BACK = 'rolled_back';
    public const RECOVERY_REQUIRED = 'recovery_required';
    public const RECOVERED_BY_OWNER = 'recovered_by_owner';
    public const CANCELLED = 'cancelled';

    public static function isTerminal(string $status): bool
    {
        $terminal = [self::SUCCEEDED, self::FAILED, self::ROLLED_BACK, self::RECOVERED_BY_OWNER, self::CANCELLED];
        return in_array($status, $terminal, true);
    }
}

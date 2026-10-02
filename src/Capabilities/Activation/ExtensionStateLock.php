<?php

declare(strict_types=1);

namespace Thallo\Core\Capabilities\Activation;

use Glueful\Database\Connection;
use Thallo\Contracts\Extensions\ExtensionStateCoordinator;

/**
 * The extension-state lock, on the framework's own key: a session advisory lock on
 * hashtext('glueful:extension-state'), the key glueful/framework's ExtensionStateMutex takes from
 * 1.88. Before 1.88 it serializes Thallo's writers of the enabled-provider list; from 1.88 it
 * also serializes them against the framework's extension commands and schema executor. A session
 * lock is re-entrant on one connection, so a sequence that reaches the framework's mutex on the
 * same connection doesn't wait on itself.
 */
final class ExtensionStateLock implements ExtensionStateCoordinator
{
    public const KEY = 'glueful:extension-state';

    public function __construct(private readonly Connection $db)
    {
    }

    public function within(callable $sequence): mixed
    {
        $pdo = $this->db->getPDO();
        $pdo->prepare('SELECT pg_advisory_lock(hashtext(?))')->execute([self::KEY]);
        try {
            return $sequence();
        } finally {
            $pdo->prepare('SELECT pg_advisory_unlock(hashtext(?))')->execute([self::KEY]);
        }
    }
}

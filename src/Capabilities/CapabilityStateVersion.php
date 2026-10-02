<?php

declare(strict_types=1);

namespace Thallo\Core\Capabilities;

use Glueful\Database\Connection;

/**
 * A counter that every capability state write advances, in the write's own transaction
 * (CapabilityStateStore::put). A route table compiled under one value is never served under
 * another (the context's route-signature input), so a capability turned off loses its routes on the
 * next request.
 */
final class CapabilityStateVersion
{
    public const KEY = 'capability.state_version';

    public function __construct(private readonly Connection $db)
    {
    }

    /** Fresh, never memoised. '0' only when the system table doesn't exist yet; anything else throws. */
    public function current(): string
    {
        try {
            $stmt = $this->db->getPDO()->prepare('SELECT value FROM thallo_system_flags WHERE key = ?');
            $stmt->execute([self::KEY]);
            $value = $stmt->fetchColumn();
        } catch (\PDOException $e) {
            if ((string) $e->getCode() === '42P01') {
                return '0';
            }
            throw $e;
        }
        return $value === false || $value === null || $value === '' ? '0' : (string) $value;
    }

    /** Advances the version. Call inside the transaction that writes the state. */
    public function advance(): void
    {
        $this->db->getPDO()->prepare(
            "INSERT INTO thallo_system_flags (key, value, updated_at) VALUES (?, '1', ?)
             ON CONFLICT (key) DO UPDATE SET
               value = (COALESCE(NULLIF(thallo_system_flags.value, ''), '0')::bigint + 1)::text,
               updated_at = EXCLUDED.updated_at"
        )->execute([self::KEY, gmdate('Y-m-d H:i:s')]);
    }
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Capabilities;

use Glueful\Database\Connection;

/**
 * Every capability switch and the capability-state version, read in one statement, once per
 * application context. The capability registry decides from it, and the same version is the
 * context's route-signature input, so the routes a request registers and the route table it may
 * reuse come from one state.
 *
 * The fallback is narrow: a missing system table (a boot before provision), or an unreachable
 * database on a console boot, gives an unavailable snapshot — decisions fall back to the config map
 * and the version is 'unavailable', which never matches a healthy state. Any other error throws.
 */
final class CapabilityStateSnapshot
{
    public const UNAVAILABLE = 'unavailable';

    /** @param array<string, string> $rows key => value from thallo_system_flags */
    public function __construct(
        public readonly array $rows,
        public readonly string $version,
        public readonly bool $available,
    ) {
    }

    public static function take(Connection $db, bool $console): self
    {
        try {
            $stmt = $db->getPDO()->prepare(
                "SELECT key, value FROM thallo_system_flags WHERE key LIKE 'capability.%' OR key = 'search_enabled'"
            );
            $stmt->execute();
            $rows = [];
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $rows[(string) $row['key']] = (string) ($row['value'] ?? '');
            }
        } catch (\PDOException $e) {
            $code = (string) $e->getCode();
            if ($code === '42P01' || ($console && str_starts_with($code, '08'))) {
                return new self([], self::UNAVAILABLE, false);
            }
            throw $e;
        }
        $version = $rows[CapabilityStateVersion::KEY] ?? '0';
        return new self($rows, $version === '' ? '0' : $version, true);
    }
}

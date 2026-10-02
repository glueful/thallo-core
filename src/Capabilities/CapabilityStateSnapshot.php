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
            return self::fallbackFor($e, $console);
        }
        $version = $rows[CapabilityStateVersion::KEY] ?? '0';
        return new self($rows, $version === '' ? '0' : $version, true);
    }

    /**
     * Opens the connection inside the same fallback as the read: resolving a Connection opens its
     * PDO, so an unreachable database fails there, before take() could catch it.
     *
     * @param callable(): Connection $connection
     */
    public static function resolve(callable $connection, bool $console): self
    {
        try {
            $db = $connection();
        } catch (\Throwable $e) {
            return self::fallbackFor($e, $console);
        }
        return self::take($db, $console);
    }

    /** A missing table, or (console only) an unreachable database, falls back; anything else is rethrown. */
    private static function fallbackFor(\Throwable $e, bool $console): self
    {
        $state = self::sqlState($e);
        if ($state === '42P01' || ($console && $state !== null && str_starts_with($state, '08'))) {
            return new self([], self::UNAVAILABLE, false);
        }
        throw $e;
    }

    /**
     * The SQLSTATE behind a failure, through wrapping exceptions. PDO's constructor reports a
     * connection failure with the driver's integer code (7) and the SQLSTATE only in errorInfo and
     * the message ("SQLSTATE[08006] [7] …").
     */
    private static function sqlState(\Throwable $e): ?string
    {
        for ($t = $e; $t !== null; $t = $t->getPrevious()) {
            if ($t instanceof \PDOException && is_string($t->errorInfo[0] ?? null) && $t->errorInfo[0] !== '') {
                return $t->errorInfo[0];
            }
            if (preg_match('/SQLSTATE\[([0-9A-Z]{5})\]/', $t->getMessage(), $m) === 1) {
                return $m[1];
            }
            $code = (string) $t->getCode();
            if ($t instanceof \PDOException && strlen($code) === 5) {
                return $code;
            }
        }
        return null;
    }
}

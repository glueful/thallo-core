<?php

declare(strict_types=1);

namespace Thallo\Core\Payments\Tenancy;

use Glueful\Database\Execution\ExecutionWrapperInterface;
use PDOStatement;
use Thallo\Tenancy\Adoption\AdoptionContributorRegistry;
use Thallo\Tenancy\Adoption\AdoptionGate;
use Thallo\Tenancy\Retrofit\RetrofitInProgressException;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Holds the {@see AdoptionGate} for the rest of the unit of work around the first statement on a
 * table adoption moves, for as long as a move can still come:
 *
 *  - payments' tables ({@see PaymentTables::workspaceOwned()}) until payments are guarded
 *    ({@see PaymentTenancyAdoption::GUARDED_FLAG}) — the flip on a single store, and the repair on a
 *    site that turned workspaces on before payments were adopted;
 *  - every other adoption contributor's tables (commerce's) until the schema is widened — they move
 *    only in the flip.
 *
 * Resolvers hold the gate for work that resolves a tenant, but some paths take a row's owner from the
 * row itself — Payvia's subscription and dispute webhooks — and then write qualified by it. Held from
 * before that first read, the owner cannot move before the write that uses it. The state is read
 * again once the hold is taken; while the flip or the repair holds the gate the statement is refused
 * (fail closed), and Payvia records a webhook failed for its retry.
 *
 * Every other statement passes on a cheap substring check, which is a strict superset of the table
 * matcher: each covered table's name contains one of the fragments, found case-insensitively.
 */
final class PaymentAdoptionGateWrapper implements ExecutionWrapperInterface
{
    private const FRAGMENTS = ['pay', 'invoices', 'billing_plans', 'subscription', 'commerce'];

    private static ?string $paymentsPattern = null;

    /** @var array{count: int, pattern: ?string, fragments: list<string>}|null */
    private ?array $adopted = null;

    public function __construct(
        private readonly SystemFlags $flags,
        private readonly AdoptionGate $gate,
        private readonly AdoptionContributorRegistry $registry,
    ) {
    }

    public function around(string $sql, array $bindings, callable $proceed): PDOStatement
    {
        if (!$this->mayMatchAny($sql)) {
            return $proceed();
        }
        $settledFlag = match (true) {
            self::matchesTable($sql) => PaymentTenancyAdoption::GUARDED_FLAG,
            $this->matchesAdopted($sql) => 'tenancy.schema_state',
            default => null,
        };
        if ($settledFlag === null || $this->gate->isHeld() || $this->settled($settledFlag)) {
            return $proceed();
        }

        if (!$this->gate->holdShared()) {
            throw new RetrofitInProgressException();
        }
        $this->flags->clearCache();
        if ($this->settled($settledFlag)) {
            $this->gate->release();
        }

        return $proceed();
    }

    /** The cheap check over payments' tables: false only when none of their names can appear. */
    public static function mayMatchTable(string $sql): bool
    {
        return self::containsAny($sql, self::fragmentsFor(PaymentTables::workspaceOwned()));
    }

    /** The table matcher over payments' tables: a name at a token boundary. */
    public static function matchesTable(string $sql): bool
    {
        self::$paymentsPattern ??= self::pattern(PaymentTables::workspaceOwned());

        return self::$paymentsPattern !== null
            && preg_match(self::$paymentsPattern, ' ' . strtolower($sql) . ' ') === 1;
    }

    /** The cheap check over every covered table. */
    public function mayMatchAny(string $sql): bool
    {
        return self::containsAny($sql, $this->adopted()['fragments']);
    }

    /** The table matcher over the other contributors' tables. */
    public function matchesAdopted(string $sql): bool
    {
        $pattern = $this->adopted()['pattern'];

        return $pattern !== null && preg_match($pattern, ' ' . strtolower($sql) . ' ') === 1;
    }

    private function settled(string $flag): bool
    {
        $value = $this->flags->get($flag);

        return $flag === 'tenancy.schema_state' ? $value === 'widened' : $value === '1';
    }

    /**
     * The other contributors' tables, rebuilt whenever a contributor registers: packs register at
     * boot, possibly after the first statement ran.
     *
     * @return array{count: int, pattern: ?string, fragments: list<string>}
     */
    private function adopted(): array
    {
        $contributors = $this->registry->all();
        if ($this->adopted !== null && $this->adopted['count'] === count($contributors)) {
            return $this->adopted;
        }
        $payments = PaymentTables::workspaceOwned();
        $tables = [];
        foreach ($contributors as $contributor) {
            foreach ($contributor->tables() as $table) {
                if (!in_array($table, $payments, true)) {
                    $tables[$table] = true;
                }
            }
        }
        $tables = array_keys($tables);

        return $this->adopted = [
            'count' => count($contributors),
            'pattern' => self::pattern($tables),
            'fragments' => self::fragmentsFor([...$payments, ...$tables]),
        ];
    }

    /** @param list<string> $tables */
    private static function pattern(array $tables): ?string
    {
        if ($tables === []) {
            return null;
        }

        return '/[\s"`\'(.](' . implode('|', array_map(
            static fn (string $table): string => preg_quote(strtolower($table), '/'),
            $tables,
        )) . ')[\s"`\'(),;]/';
    }

    /**
     * The fixed fragments, plus the full name of any table none of them covers — so a table added
     * later can never be skipped by the cheap check.
     *
     * @param list<string> $tables
     * @return list<string>
     */
    private static function fragmentsFor(array $tables): array
    {
        $fragments = self::FRAGMENTS;
        foreach ($tables as $table) {
            $covered = false;
            foreach (self::FRAGMENTS as $fragment) {
                $covered = $covered || stripos($table, $fragment) !== false;
            }
            if (!$covered) {
                $fragments[] = $table;
            }
        }

        return $fragments;
    }

    /** @param list<string> $fragments */
    private static function containsAny(string $sql, array $fragments): bool
    {
        foreach ($fragments as $fragment) {
            if (stripos($sql, $fragment) !== false) {
                return true;
            }
        }

        return false;
    }
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Payments\Tenancy;

use Glueful\Database\Execution\ExecutionWrapperInterface;
use PDOStatement;
use Thallo\Tenancy\Adoption\AdoptionGate;
use Thallo\Tenancy\Retrofit\RetrofitInProgressException;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Holds the {@see AdoptionGate} for the rest of the unit of work around the first statement on a
 * payments table, until payments are guarded ({@see PaymentTenancyAdoption::GUARDED_FLAG}).
 *
 * {@see ThalloPayviaTenantResolver} holds it for every read and write that resolves a tenant, but
 * Payvia's tenantless paths — subscription and dispute webhooks — take a row's owner from the row
 * itself and then write qualified by that owner. Held from before that first read, the owner cannot
 * be moved before the write that uses it: not by the flip on a single store, and not by the repair
 * on a site that turned workspaces on before payments were adopted, which is why the hold outlives
 * the widened schema and ends only when the guard is certified. The flag is read again once the hold
 * is taken; while the flip or the repair holds the gate, the statement is refused (fail closed) and
 * Payvia records the webhook failed for its retry.
 *
 * Every other statement passes on a cheap substring check, which is a strict superset of the table
 * matcher: each inventory table's name contains one of {@see fragments()}, found case-insensitively.
 */
final class PaymentAdoptionGateWrapper implements ExecutionWrapperInterface
{
    private const FRAGMENTS = ['pay', 'invoices', 'billing_plans', 'subscription'];

    private static ?string $pattern = null;

    /** @var list<string>|null */
    private static ?array $fragments = null;

    public function __construct(
        private readonly SystemFlags $flags,
        private readonly AdoptionGate $gate,
    ) {
    }

    public function around(string $sql, array $bindings, callable $proceed): PDOStatement
    {
        if (
            !self::mayMatchTable($sql)
            || !self::matchesTable($sql)
            || $this->gate->isHeld()
            || $this->flags->get(PaymentTenancyAdoption::GUARDED_FLAG) === '1'
        ) {
            return $proceed();
        }

        if (!$this->gate->holdShared()) {
            throw new RetrofitInProgressException();
        }
        $this->flags->clearCache();
        if ($this->flags->get(PaymentTenancyAdoption::GUARDED_FLAG) === '1') {
            $this->gate->release();
        }

        return $proceed();
    }

    /** The cheap check: false only when no inventory table name can appear in the statement. */
    public static function mayMatchTable(string $sql): bool
    {
        foreach (self::fragments() as $fragment) {
            if (stripos($sql, $fragment) !== false) {
                return true;
            }
        }

        return false;
    }

    /** The table matcher: an inventory table name at a token boundary. */
    public static function matchesTable(string $sql): bool
    {
        self::$pattern ??= '/[\s"`\'(.](' . implode('|', array_map(
            static fn (string $table): string => preg_quote($table, '/'),
            PaymentTables::workspaceOwned(),
        )) . ')[\s"`\'(),;]/';

        return preg_match(self::$pattern, ' ' . strtolower($sql) . ' ') === 1;
    }

    /**
     * The fixed fragments, plus the full name of any inventory table none of them covers — so a
     * table added to the inventory later can never be skipped by the cheap check.
     *
     * @return list<string>
     */
    private static function fragments(): array
    {
        if (self::$fragments !== null) {
            return self::$fragments;
        }
        $fragments = self::FRAGMENTS;
        foreach (PaymentTables::workspaceOwned() as $table) {
            $covered = false;
            foreach (self::FRAGMENTS as $fragment) {
                $covered = $covered || str_contains($table, $fragment);
            }
            if (!$covered) {
                $fragments[] = $table;
            }
        }

        return self::$fragments = $fragments;
    }
}

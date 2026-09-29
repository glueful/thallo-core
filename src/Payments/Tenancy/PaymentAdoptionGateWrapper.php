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
 * payments table while the schema is not widened.
 *
 * {@see ThalloPayviaTenantResolver} holds it for every read and write that resolves a tenant, but
 * Payvia's tenantless paths — subscription and dispute webhooks — take a row's owner from the row
 * itself. Held from before that first read, the owner it returns cannot be moved by the flip before
 * the write that uses it. The schema state is read again once the hold is taken: a flip that
 * committed in between needs no hold, and one in progress refuses the statement (fail closed).
 *
 * Every other statement passes through untouched; once the schema is widened it never goes back, so
 * a widened state read from the flags' memo is final.
 */
final class PaymentAdoptionGateWrapper implements ExecutionWrapperInterface
{
    private static ?string $pattern = null;

    public function __construct(
        private readonly SystemFlags $flags,
        private readonly AdoptionGate $gate,
    ) {
    }

    public function around(string $sql, array $bindings, callable $proceed): PDOStatement
    {
        if (!self::touchesPayments($sql) || $this->gate->isHeld() || $this->flags->schemaState() === 'widened') {
            return $proceed();
        }

        if (!$this->gate->holdShared()) {
            throw new RetrofitInProgressException();
        }
        $this->flags->clearCache();
        if ($this->flags->schemaState() === 'widened') {
            $this->gate->release();
        }

        return $proceed();
    }

    private static function touchesPayments(string $sql): bool
    {
        self::$pattern ??= '/[\s"`\'(](' . implode('|', array_map(
            static fn (string $table): string => preg_quote($table, '/'),
            PaymentTables::workspaceOwned(),
        )) . ')[\s"`\'(),;]/';

        return preg_match(self::$pattern, ' ' . strtolower($sql) . ' ') === 1;
    }
}

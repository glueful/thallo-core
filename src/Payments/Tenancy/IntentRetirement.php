<?php

declare(strict_types=1);

namespace Thallo\Core\Payments\Tenancy;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Extensions\Payvia\Repositories\PaymentIntentRepository;
use Glueful\Extensions\Payvia\Tenancy\ExplicitTenantResolver;
use Glueful\Extensions\Payvia\Tenancy\SentinelTenantResolver;

/**
 * Supersedes one payment intent an operator names, to clear the duplicate that blocks the payments
 * repair. Reaches only intents with no workspace or in the default workspace, and retires through
 * Payvia's own conditional retirement — scoped, with an explicit resolver, to the intent's own
 * partition — so an intent that settled in the meantime is never superseded, and its idempotency
 * key is re-keyed exactly as Payvia would.
 *
 * Superseding is local bookkeeping: it cancels and refunds nothing at the payment provider.
 */
final class IntentRetirement
{
    public const RETIRED = 'retired';
    public const ALREADY = 'already';
    public const INACTIVE = 'inactive';
    public const FOREIGN = 'foreign';
    public const MISSING = 'missing';

    private const ACTIVE = ['initializing', 'open'];

    public function __construct(
        private readonly Connection $connection,
        private readonly ApplicationContext $context,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function find(string $uuid): ?array
    {
        $row = $this->asSystem(fn (): mixed => $this->connection->table('payment_intents')
            ->where('uuid', '=', $uuid)
            ->first());

        return is_array($row) ? $row : null;
    }

    /** What retiring $uuid would do (or did not do) — without changing anything. */
    public function outcomeFor(?array $intent, string $defaultTenant): ?string
    {
        if ($intent === null) {
            return self::MISSING;
        }
        if (!in_array((string) $intent['tenant_uuid'], ['', $defaultTenant], true)) {
            return self::FOREIGN;
        }
        if ((string) $intent['status'] === 'superseded') {
            return self::ALREADY;
        }

        return in_array((string) $intent['status'], self::ACTIVE, true) ? null : self::INACTIVE;
    }

    public function retire(string $uuid, string $defaultTenant): string
    {
        $intent = $this->find($uuid);
        $outcome = $this->outcomeFor($intent, $defaultTenant);
        if ($outcome !== null) {
            return $outcome;
        }

        $tenant = (string) $intent['tenant_uuid'];
        $intents = new PaymentIntentRepository(
            $this->connection,
            $this->context,
            $tenant === '' ? new SentinelTenantResolver() : new ExplicitTenantResolver($tenant),
        );
        // System context only gets it past the tenancy backstop: the explicit resolver above already
        // confines Payvia's retirement to the intent's own partition.
        $superseded = (bool) $this->asSystem(fn (): bool => $intents->supersede($this->context, $uuid));
        if ($superseded) {
            return self::RETIRED;
        }

        // Lost the conditional update: it settled, failed or was superseded meanwhile.
        return $this->outcomeFor($this->find($uuid), $defaultTenant) ?? self::INACTIVE;
    }

    private function asSystem(callable $work): mixed
    {
        $runner = $this->runner();

        return $runner === null ? $work() : $runner->runAsSystem($work);
    }

    private function runner(): ?TenantContextRunner
    {
        $container = $this->context->getContainer();
        if (!$container->has(TenantContextRunner::class)) {
            return null;
        }
        $runner = $container->get(TenantContextRunner::class);

        return $runner instanceof TenantContextRunner ? $runner : null;
    }
}

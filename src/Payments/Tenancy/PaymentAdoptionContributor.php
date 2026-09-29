<?php

declare(strict_types=1);

namespace Thallo\Core\Payments\Tenancy;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Tenancy\Adoption\AdoptionContributor;

/**
 * Payments' part in enable-time adoption. The flip that widens the schema runs every contributor
 * inside its own transaction, so payments' unassigned rows reach the default workspace at the same
 * instant payments start resolving it — never before, never after — and a key the workspace already
 * holds, or a row another workspace owns, refuses the flip instead of merging silently
 * ({@see PaymentTenancyAdoption::apply()}).
 *
 * Payvia's own TenantAdopter is not reused: it refuses outright once any other workspace has rows,
 * so it cannot also serve the repair of sites enabled before this contributor existed.
 */
final class PaymentAdoptionContributor implements AdoptionContributor
{
    public const ID = 'thallo.payments';

    public function __construct(private readonly PaymentTenancyAdoption $adoption)
    {
    }

    public function id(): string
    {
        return self::ID;
    }

    /**
     * What Payvia registers with the tenancy backstop itself, which FinalizationProbe checks before
     * ON. Adoption moves more than this — {@see PaymentTables::workspaceOwned()} — but a table Payvia
     * does not register here would block every enablement.
     *
     * @return list<string>
     */
    public function tables(): array
    {
        return PaymentTables::backstopRegistered();
    }

    public function adopt(ApplicationContext $context, string $tenantUuid): void
    {
        $this->adoption->apply($tenantUuid);
    }
}

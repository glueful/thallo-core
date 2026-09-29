<?php

declare(strict_types=1);

namespace Thallo\Core\Payments\Tenancy;

use Glueful\Extensions\Payvia\Support\DiagnosticsReport;

/**
 * The one inventory of payments' workspace-owned tables. Adoption, the repair's diagnostics, the
 * database guard and the adoption gate's statement matcher all read {@see workspaceOwned()}, so they
 * cannot drift apart again.
 *
 * It is Payvia's own tenant-table list plus `payvia_transfers`, which Payvia's
 * PayoutTransferRepository scopes by the same tenant resolver but its DiagnosticsReport omits: a
 * payout started as the single store must reach the default workspace with everything else, or its
 * retry cannot find it and pays out again.
 *
 * {@see backstopRegistered()} is kept separate on purpose: it is what Payvia itself registers with
 * the tenancy backstop, and the finalization probe refuses ON for any contributor table that is not
 * registered there — naming `payvia_transfers` would block every enablement.
 */
final class PaymentTables
{
    public const TRANSFERS = 'payvia_transfers';

    /** @return list<string> */
    public static function workspaceOwned(): array
    {
        return array_values(array_unique([...DiagnosticsReport::tenantTables(), self::TRANSFERS]));
    }

    /** @return list<string> the tables Payvia registers with the tenancy backstop */
    public static function backstopRegistered(): array
    {
        return DiagnosticsReport::tenantTables();
    }
}

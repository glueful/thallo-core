<?php

declare(strict_types=1);

namespace Thallo\Core\Payments\Tenancy;

/** The move was refused — nothing was written. The report says which rows and why. */
final class PaymentAdoptionRefusedException extends \RuntimeException
{
    public function __construct(public readonly PaymentAdoptionReport $report)
    {
        parent::__construct(sprintf(
            'Unassigned payments were not moved into workspace %s: %d key collision(s) with rows it already '
            . 'holds and %d row(s) another workspace owns. Run php glueful thallo:tenancy:payments:repair '
            . 'for the rows.',
            $report->tenantUuid,
            count($report->collisions),
            count($report->ambiguous),
        ));
    }
}

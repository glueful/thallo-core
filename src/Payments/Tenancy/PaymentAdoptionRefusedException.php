<?php

declare(strict_types=1);

namespace Thallo\Core\Payments\Tenancy;

/**
 * The move was refused — nothing was written. The message names the rows (up to ten) and ends with
 * $recovery: what to do next depends on where the move ran, so the caller supplies it.
 */
final class PaymentAdoptionRefusedException extends \RuntimeException
{
    private const LISTED = 10;

    public function __construct(public readonly PaymentAdoptionReport $report, string $recovery)
    {
        $lines = [];
        foreach ($report->collisions as $c) {
            $key = implode(', ', array_map(
                static fn (string $column, mixed $value): string => $column . '=' . (string) $value,
                array_keys($c['key']),
                $c['key'],
            ));
            $lines[] = "{$c['table']} #{$c['unassigned_id']} ({$key}) clashes with #{$c['existing_id']}";
        }
        foreach ($report->ambiguous as $a) {
            $lines[] = "{$a['table']} #{$a['id']}: {$a['reason']} ({$a['workspace']})";
        }
        $more = count($lines) > self::LISTED ? sprintf(' (and %d more)', count($lines) - self::LISTED) : '';

        parent::__construct(sprintf(
            'Payments with no workspace could not join workspace %s: %s%s. Nothing was changed. %s',
            $report->tenantUuid,
            implode('; ', array_slice($lines, 0, self::LISTED)),
            $more,
            $recovery,
        ));
    }

    public function withRecovery(string $recovery): self
    {
        return new self($this->report, $recovery);
    }
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

/**
 * Write-ahead record of every rename of the live install (spec §5). Each rename is persisted as
 * intended, performed, then marked done; a fresh runner settles intended entries from the
 * filesystem (resume) and can reverse everything (rollback), itself journaled, from any kill point.
 */
final class Journal
{
    public function __construct(
        private readonly OperationStore $ops,
        private readonly string $id,
        private readonly string $owner,
        private readonly ?\Closure $killPoint = null,
        // The runner's fixed set (spec §16.2): fn(string $from, string $to): bool. Null allows any
        // rename (unit tests of the journal's mechanics only).
        private readonly ?\Closure $allowed = null,
    ) {
    }

    public function rename(string $from, string $to, string $label): void
    {
        $this->journaled('forward', null, $label, $from, $to);
    }

    public function resume(): void
    {
        $this->ops->update($this->id, $this->owner, static function (array $op): array {
            foreach ($op['journal'] as $i => $e) {
                if ($e['phase'] !== 'intended') {
                    continue;
                }
                $fromExists = file_exists($e['from']) || is_link($e['from']);
                $toExists = file_exists($e['to']) || is_link($e['to']);
                if (!$fromExists && $toExists) {
                    $op['journal'][$i]['phase'] = 'done';
                } elseif ($fromExists && !$toExists) {
                    $op['journal'][$i]['phase'] = 'abandoned';
                } else {
                    throw new JournalConflict("Cannot tell whether {$e['from']} → {$e['to']} happened");
                }
            }
            return $op;
        });
    }

    public function hasForwardChanges(): bool
    {
        return $this->pendingReversals() !== [];
    }

    public function rollback(): void
    {
        foreach ($this->pendingReversals() as $e) {
            $this->journaled('reverse', $e['seq'], 'undo.' . $e['label'], $e['to'], $e['from']);
        }
    }

    /** @return list<array> done forward entries without a done reverse, newest first */
    private function pendingReversals(): array
    {
        $journal = $this->ops->current()['journal'] ?? [];
        $reversed = [];
        foreach ($journal as $e) {
            if ($e['kind'] === 'reverse' && $e['phase'] === 'done') {
                $reversed[$e['reverses']] = true;
            }
        }
        $pending = array_values(array_filter(
            $journal,
            static fn (array $e): bool => $e['kind'] === 'forward'
                && $e['phase'] === 'done'
                && !isset($reversed[$e['seq']]),
        ));
        return array_reverse($pending);
    }

    private function journaled(string $kind, ?int $reverses, string $label, string $from, string $to): void
    {
        if ($this->allowed !== null && !($this->allowed)($from, $to)) {
            throw new \RuntimeException("Refused a rename outside the runner's fixed set: {$from} → {$to}");
        }
        $seq = 0;
        $entry = [
            'kind' => $kind,
            'reverses' => $reverses,
            'label' => $label,
            'from' => $from,
            'to' => $to,
            'phase' => 'intended',
        ];
        $this->ops->update($this->id, $this->owner, static function (array $op) use ($entry, &$seq): array {
            $seq = count($op['journal']) + 1;
            $op['journal'][] = ['seq' => $seq] + $entry;
            return $op;
        });
        $this->kill($label . '.intended');
        if (!is_dir(dirname($to))) {
            mkdir(dirname($to), 0770, true);
        }
        if (!@rename($from, $to)) {
            $why = error_get_last()['message'] ?? '';
            throw new \RuntimeException("rename {$from} → {$to} failed" . ($why !== '' ? ": {$why}" : ''));
        }
        $this->kill($label . '.renamed');
        $this->ops->update($this->id, $this->owner, static function (array $op) use ($seq): array {
            $op['journal'][$seq - 1]['phase'] = 'done';
            return $op;
        });
    }

    private function kill(string $label): void
    {
        if ($this->killPoint !== null) {
            ($this->killPoint)($label);
        }
    }
}

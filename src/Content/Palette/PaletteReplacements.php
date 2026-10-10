<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Palette;

use Glueful\Database\Connection;

/**
 * Replacement records (custom palette spec §5.3; plan Task 12): each completed replace job — its slot,
 * the token map it applied, and the palette generation it completed at — sent to editors as complete
 * batches over a generation range, so each editor applies each record once, in order, and only to the
 * state it held before that record.
 */
final class PaletteReplacements
{
    public const SCHEMA_DAYS = 90;

    private ?\Closure $afterRecordsRead = null;

    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Every completed record with `after < completed_generation <= through`, in order. Atomic with
     * pruning: the records are read FIRST and the horizon AFTER — the pruner deletes records and raises
     * the horizon in one transaction, so a deletion that could hide a row from the first read is seen
     * by the second, and the batch expires rather than arriving truncated.
     *
     * @return array{after: int, through: int, records: list<array{id: string, slot: int, map: array<string,string>,
     *     completed_generation: int}>}
     * @throws PaletteHistoryExpired
     */
    public function batch(int $after, int $through): array
    {
        $rows = $this->db->table('palette_jobs')
            ->where('status', '=', 'completed')
            ->where('completed_generation', '>', $after)
            ->where('completed_generation', '<=', $through)
            ->orderBy('completed_generation', 'ASC')
            ->get();
        if ($this->afterRecordsRead !== null) {
            $fn = $this->afterRecordsRead;
            $this->afterRecordsRead = null;
            $fn(); // concurrency proofs only: a prune commits here, between the two reads
        }
        $horizon = $this->horizon();
        if ($after < $horizon) {
            throw new PaletteHistoryExpired($after, $horizon);
        }
        return ['after' => $after, 'through' => $through, 'records' => array_map(self::record(...), $rows)];
    }

    /**
     * The style schema's batch: from the horizon (or the last completion older than SCHEMA_DAYS) to now.
     *
     * @return array{after: int, through: int, records: list<array<string,mixed>>}
     */
    public function forSchema(int $generation): array
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - self::SCHEMA_DAYS * 86400);
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $old = $this->db->table('palette_jobs')
                ->select(['completed_generation'])
                ->where('status', '=', 'completed')
                ->where('finished_at', '<', $cutoff)
                ->orderBy('completed_generation', 'DESC')
                ->first();
            $after = max($this->horizon(), (int) ($old['completed_generation'] ?? 0));
            try {
                return $this->batch(min($after, $generation), $generation);
            } catch (PaletteHistoryExpired) {
                continue; // a prune raised the floor between the reads: read it again
            }
        }
        throw new \RuntimeException('the palette history kept moving while the schema was read');
    }

    /**
     * Concurrency proofs only: runs once, right after the next batch's record read.
     */
    public function afterRecordsRead(\Closure $fn): void
    {
        $this->afterRecordsRead = $fn;
    }

    public function horizon(): int
    {
        $row = $this->db->table('palette_state')->select(['history_horizon'])->first();
        return (int) ($row['history_horizon'] ?? 0);
    }

    /**
     * @param array<string,mixed> $row
     * @return array{id: string, slot: int, map: array<string,string>, completed_generation: int}
     */
    private static function record(array $row): array
    {
        $slot = (int) $row['slot'];
        $map = ["color.brand-{$slot}" => (string) $row['to_token']];
        if (isset($row['contrast_to_token']) && $row['contrast_to_token'] !== '') {
            $map["color.brand-{$slot}-contrast"] = (string) $row['contrast_to_token'];
        }
        return [
            'id' => (string) $row['id'],
            'slot' => $slot,
            'map' => $map,
            'completed_generation' => (int) $row['completed_generation'],
        ];
    }
}

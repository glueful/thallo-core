<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Palette;

use Glueful\Database\Connection;

/**
 * Prunes finished replace jobs older than the retention window (custom palette plan Task 12) and
 * raises the history horizon to the newest completion it removed, in one transaction under the
 * palette row — so a batch read either sees every record of its range or sees the raised horizon and
 * expires, never a truncated batch labelled complete.
 */
final class PaletteHistoryPruner
{
    public function __construct(
        private readonly Connection $db,
        private readonly PaletteFence $fence,
        private readonly PaletteState $state,
    ) {
    }

    /** @return int jobs removed */
    public function prune(int $days = PaletteReplacements::SCHEMA_DAYS): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        return $this->fence->within(function () use ($cutoff): int {
            $this->state->lock();
            $old = $this->db->table('palette_jobs')
                ->whereIn('status', ['completed', 'cancelled'])
                ->where('finished_at', '<', $cutoff)
                ->get();
            if ($old === []) {
                return 0;
            }
            $highest = 0;
            foreach ($old as $row) {
                $highest = max($highest, (int) ($row['completed_generation'] ?? 0));
            }
            $row = $this->db->table('palette_state')->select(['history_horizon'])->first();
            $current = (int) ($row['history_horizon'] ?? 0);
            if ($highest > $current) {
                $this->db->table('palette_state')->where('site', '=', 'site')->update(['history_horizon' => $highest]);
            }
            $ids = array_map(static fn (array $r): string => (string) $r['id'], $old);
            $this->db->table('palette_jobs')->whereIn('id', $ids)
                ->delete();
            return count($old);
        });
    }
}

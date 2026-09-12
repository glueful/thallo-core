<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Retention;

/**
 * Mutable accumulator returned by VersionPruner and printed by the command.
 * Counts are summed across every (entry, locale) lineage scanned in a pass.
 */
final class PruneReport
{
    public int $lineagesScanned = 0;
    public int $versionsDeleted = 0;
    public int $versionsRetained = 0;
    public int $pinnedSkipped = 0;

    public function recordLineage(int $deleted, int $retained, int $pinnedSkipped): void
    {
        $this->lineagesScanned++;
        $this->versionsDeleted += $deleted;
        $this->versionsRetained += $retained;
        $this->pinnedSkipped += $pinnedSkipped;
    }

    /** @return array{lineages_scanned:int,versions_deleted:int,versions_retained:int,pinned_skipped:int} */
    public function toArray(): array
    {
        return [
            'lineages_scanned' => $this->lineagesScanned,
            'versions_deleted' => $this->versionsDeleted,
            'versions_retained' => $this->versionsRetained,
            'pinned_skipped' => $this->pinnedSkipped,
        ];
    }
}

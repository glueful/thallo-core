<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Palette;

use Thallo\Contracts\Style\Palette;

/**
 * The palette state a writer read or holds (custom palette spec §4.3): its generation, the palette,
 * and the active replace jobs with the slots they reserve.
 */
final class PaletteSnapshot
{
    /** @param list<PaletteJob> $activeJobs status running or failed */
    public function __construct(
        public readonly int $generation,
        public readonly Palette $palette,
        public readonly array $activeJobs,
    ) {
    }

    /** The active job replacing a slot, if any. */
    public function jobReplacing(int $slot): ?PaletteJob
    {
        foreach ($this->activeJobs as $job) {
            if ($job->slot === $slot) {
                return $job;
            }
        }
        return null;
    }

    /** @return list<int> the brand slots active jobs may write (their destinations) */
    public function reservedSlots(): array
    {
        $out = [];
        foreach ($this->activeJobs as $job) {
            foreach (array_filter([$job->to, $job->contrastTo]) as $token) {
                $slot = Palette::slotOf($token);
                if ($slot !== null) {
                    $out[$slot] = $slot;
                }
            }
        }
        sort($out);
        return array_values($out);
    }
}

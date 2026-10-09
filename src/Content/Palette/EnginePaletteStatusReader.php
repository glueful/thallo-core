<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Palette;

use Thallo\Contracts\Style\PaletteStatusReader;

/** The pickers' view of the running replacements (custom palette spec §5.2), from the palette state. */
final class EnginePaletteStatusReader implements PaletteStatusReader
{
    public function __construct(private readonly PaletteState $state)
    {
    }

    public function statuses(): array
    {
        $snapshot = $this->state->snapshot();
        $out = [];
        foreach ($snapshot->activeJobs as $job) {
            $out[$job->slot] = [
                'reserved' => false,
                'replacing' => ['to' => $job->to, 'contrast_to' => $job->contrastTo],
            ];
        }
        foreach ($snapshot->reservedSlots() as $slot) {
            $out[$slot] = ['reserved' => true, 'replacing' => $out[$slot]['replacing'] ?? null];
        }
        return $out;
    }
}

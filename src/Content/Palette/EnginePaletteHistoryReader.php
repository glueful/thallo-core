<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Palette;

use Thallo\Contracts\Style\PaletteHistoryReader;
use Thallo\Core\Settings\GeneralSettings;

/** The style schema's palette generation and batch (custom palette spec §5.3), from the palette state. */
final class EnginePaletteHistoryReader implements PaletteHistoryReader
{
    public function __construct(
        private readonly PaletteState $state,
        private readonly PaletteReplacements $replacements,
        private readonly GeneralSettings $settings,
    ) {
    }

    public function read(callable $read): array
    {
        // each attempt reads the settings as committed, not this request's cached copy
        [$result, $generation] = $this->state->consistentRead(function () use ($read): mixed {
            $this->settings->clearStoreCache();
            return $read();
        });
        return [$result, $generation, $this->replacements->forSchema($generation)];
    }
}

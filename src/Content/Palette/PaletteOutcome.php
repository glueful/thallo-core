<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Palette;

/**
 * What a fenced write tells the editor (custom palette spec §4.5; plan Task 12): the colours
 * normalisation changed, located by block id, and the palette generation the write held — the upper
 * bound of the replacement records sent with it.
 */
final class PaletteOutcome
{
    /** @param list<array{location: string, from: string, to: string}> $rewrites */
    public function __construct(
        public readonly array $rewrites,
        public readonly int $generation,
    ) {
    }
}

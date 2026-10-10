<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Palette;

/**
 * What a palette save wrote (custom palette plan ruling 13), captured inside its transaction: whether
 * the palette changed, and the stored brand colour list it wrote — the exact list, ids and revision a
 * response must describe, never a later read another request may have changed.
 */
final class PaletteSaved
{
    public function __construct(public readonly bool $changed, public readonly ?string $brandColors)
    {
    }
}

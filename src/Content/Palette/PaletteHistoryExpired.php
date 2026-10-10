<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Palette;

/** Replacement records below the history horizon may have been pruned: the range cannot be promised whole. */
final class PaletteHistoryExpired extends \RuntimeException
{
    public function __construct(public readonly int $after, public readonly int $horizon)
    {
        parent::__construct("palette history before generation {$horizon} has been pruned");
    }
}

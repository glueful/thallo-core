<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Blocks;

/**
 * The ONE authoritative block-nesting depth cap (nesting amendment §A2): the entry's
 * blocks field is depth 1, children 2, grandchildren 3 (section → columns →
 * elements). The render pack and the SPA each carry their OWN named constant (the
 * pack cannot import Thallo\Core\) — tests assert the three surfaces agree, because the cap
 * is one rule expressed three times.
 */
final class BlockDepth
{
    public const MAX = 3;
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Blocks;

/**
 * The ONE authoritative block-nesting depth cap (visual builder spec §5.2): the entry's
 * blocks field is depth 1, children 2, and so on to 5 (section → columns → card →
 * container → heading). The render pack and the SPA each carry their OWN named constant (the
 * pack cannot import Thallo\Core\) — tests assert the three surfaces agree, because the cap
 * is one rule expressed three times.
 */
final class BlockDepth
{
    public const MAX = 5;
}

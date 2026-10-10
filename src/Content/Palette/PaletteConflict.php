<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Palette;

/**
 * A palette change a running replacement forbids (custom palette spec §5.2): renaming, re-colouring
 * or clearing the slot it replaces, or clearing a slot it writes to. Answered 409 with the message.
 */
final class PaletteConflict extends \RuntimeException
{
}

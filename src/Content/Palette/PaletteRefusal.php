<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Palette;

/** A document names a colour the palette no longer has (custom palette spec §4.5): a 422 per location. */
final class PaletteRefusal extends \RuntimeException
{
    /** @param array<string,string> $errors location => message */
    public function __construct(public readonly array $errors)
    {
        parent::__construct(implode('; ', $errors));
    }
}

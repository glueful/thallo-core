<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Style\Classes;

/** Another class of the site already carries this name, case-insensitively (spec §4.1). */
final class StyleClassNameTaken extends \RuntimeException
{
    public function __construct(public readonly string $name)
    {
        parent::__construct("the name \"{$name}\" is already used by another style class");
    }
}

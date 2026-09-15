<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Style\Classes;

/** A document write introduced a reference to an archived class (visual builder spec §4.5). */
final class StyleClassArchived extends \RuntimeException
{
    public function __construct(public readonly string $id)
    {
        parent::__construct("style class {$id} is archived and cannot be newly applied");
    }
}

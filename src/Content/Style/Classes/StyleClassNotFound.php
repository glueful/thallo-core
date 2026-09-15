<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Style\Classes;

final class StyleClassNotFound extends \RuntimeException
{
    public function __construct(public readonly string $id)
    {
        parent::__construct("style class {$id} does not exist");
    }
}

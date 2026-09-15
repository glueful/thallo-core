<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Style\Classes;

/** A job holds the class: no edit and no new reference until it completes (spec §4.5). */
final class StyleClassLocked extends \RuntimeException
{
    public function __construct(public readonly string $id, public readonly string $job)
    {
        parent::__construct("style class {$id} is locked by job {$job}");
    }
}

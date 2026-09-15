<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Style\Classes;

/** A class write named a `version` the row has moved past (visual builder spec §4.3). */
final class StyleClassVersionConflict extends \RuntimeException
{
    public function __construct(public readonly string $id, public readonly int $currentVersion)
    {
        parent::__construct("style class {$id} is at version {$currentVersion}");
    }
}

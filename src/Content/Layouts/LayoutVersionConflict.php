<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Layouts;

/** A layout write at a version that is no longer the stored one (type layouts spec §5.5). */
final class LayoutVersionConflict extends \RuntimeException
{
    public function __construct(public readonly int $current)
    {
        parent::__construct("The layout is at version {$current}.");
    }
}

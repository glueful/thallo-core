<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Regions;

/** A region write against a version that has moved (regions-stage spec §4.5): nothing was written. */
final class RegionVersionConflict extends \RuntimeException
{
    /** @param list<string> $moved the regions whose stored version differs from the expected one */
    public function __construct(public readonly array $moved)
    {
        parent::__construct('region changed since it was loaded: ' . implode(', ', $moved));
    }
}

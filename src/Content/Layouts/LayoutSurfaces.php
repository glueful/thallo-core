<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Layouts;

use Thallo\Contracts\Layouts\LayoutSurface;
use Thallo\Contracts\Layouts\LayoutSurfaceRegistry;

/** The page kinds layouts can describe: core's entry surface in Release A; packs add theirs later. */
final class LayoutSurfaces implements LayoutSurfaceRegistry
{
    /** @var array<string, LayoutSurface> */
    private array $surfaces = [];

    public function __construct(EntrySurface $entry)
    {
        $this->surfaces[$entry->key()] = $entry;
    }

    public function get(string $key): ?LayoutSurface
    {
        return $this->surfaces[$key] ?? null;
    }

    public function all(): array
    {
        return array_values($this->surfaces);
    }
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Layouts;

use Thallo\Contracts\Layouts\LayoutSurface;
use Thallo\Contracts\Layouts\LayoutSurfaceRegistry;

/**
 * The page kinds layouts can describe: core's entry, listing and archive surfaces, and what packs
 * register (the shop's product page).
 */
final class LayoutSurfaces implements LayoutSurfaceRegistry
{
    /** @var array<string, LayoutSurface> */
    private array $surfaces = [];

    public function __construct(EntrySurface $entry, ListingSurface $listing, ArchiveSurface $archive)
    {
        foreach ([$entry, $listing, $archive] as $surface) {
            $this->surfaces[$surface->key()] = $surface;
        }
    }

    public function get(string $key): ?LayoutSurface
    {
        return $this->surfaces[$key] ?? null;
    }

    public function register(LayoutSurface $surface): void
    {
        $this->surfaces[$surface->key()] = $surface;
    }

    public function all(): array
    {
        return array_values($this->surfaces);
    }
}

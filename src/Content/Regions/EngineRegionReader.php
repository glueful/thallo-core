<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Regions;

use Thallo\Contracts\Content\RegionReader;

/** RegionRepository-backed reader; per-request resolution, no cross-request memo. */
final class EngineRegionReader implements RegionReader
{
    public function __construct(private readonly RegionRepository $regions)
    {
    }

    public function blocks(string $slug): ?array
    {
        // Pinned null/fallback rule (global-regions spec §12): absent row,
        // saved-empty list AND an unavailable store (e.g. table not migrated)
        // are the same null — fallback chrome; a chrome read must never take
        // the page down. Hiding is _presentation's job, never an empty region.
        try {
            $row = $this->regions->find($slug);
        } catch (\Throwable) {
            return null;
        }
        if ($row === null || $row['blocks'] === []) {
            return null;
        }
        return $row['blocks'];
    }

    public function settings(string $slug): array
    {
        try {
            return $this->regions->find($slug)['settings'] ?? [];
        } catch (\Throwable) {
            return [];
        }
    }
}

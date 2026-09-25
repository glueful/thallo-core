<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Regions;

use Glueful\Database\Connection;
use Thallo\Core\Content\Validation\ValidationException;

/**
 * The one serialized section a region save runs (regions-stage spec §4.5), shared by the batch
 * endpoint and the per-region endpoint: under the region lock it checks BOTH regions' expected
 * versions (the unchanged one included), validates the complete candidate — the posted regions
 * over the stored ones, read under the lock — and writes every posted region, or none.
 */
final class RegionSaver
{
    private readonly RegionWriteLock $lock;

    public function __construct(
        private readonly Connection $db,
        private readonly RegionRepository $regions,
        private readonly RegionValidator $validator,
        ?RegionWriteLock $lock = null,
    ) {
        $this->lock = $lock ?? new RegionWriteLock($db);
    }

    /**
     * @param array<string, array{blocks?: mixed, settings?: mixed}> $posted the regions to write, by slug
     * @param array<string, ?int> $expected every region's version as loaded (`null` = no row yet)
     * @return array<string, array<string,mixed>> both regions as committed:
     *         `{blocks, settings, lock_version}` each
     * @throws RegionVersionConflict when a region moved (nothing written)
     * @throws ValidationException when the candidate is invalid (nothing written)
     */
    public function save(array $posted, array $expected, ?string $updatedBy): array
    {
        return $this->lock->within(function () use ($posted, $expected, $updatedBy): array {
            $stored = [];
            $moved = [];
            foreach (RegionDefinitions::slugs() as $slug) {
                $stored[$slug] = $this->regions->find($slug);
                $version = $stored[$slug]['lock_version'] ?? null;
                if (!array_key_exists($slug, $expected) || $expected[$slug] !== $version) {
                    $moved[] = $slug;
                }
            }
            if ($moved !== []) {
                throw new RegionVersionConflict($moved);
            }

            $candidate = [];
            foreach (RegionDefinitions::slugs() as $slug) {
                $candidate[$slug] = $posted[$slug] ?? [
                    'blocks' => $stored[$slug]['blocks'] ?? [],
                    'settings' => $stored[$slug]['settings'] ?? [],
                ];
            }
            $clean = $this->validator->validateBoth($candidate);

            foreach (RegionDefinitions::slugs() as $slug) {
                if (array_key_exists($slug, $posted)) {
                    $this->regions->saveExpected(
                        $slug,
                        $clean[$slug]['blocks'],
                        $clean[$slug]['settings'],
                        $expected[$slug],
                        $updatedBy,
                    );
                }
            }

            $committed = [];
            foreach (RegionDefinitions::slugs() as $slug) {
                $row = $this->regions->find($slug);
                $committed[$slug] = [
                    'blocks' => $row['blocks'] ?? [],
                    'settings' => $row['settings'] ?? [],
                    'lock_version' => $row['lock_version'] ?? null,
                ];
            }
            return $committed;
        });
    }
}

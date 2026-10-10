<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Regions;

use Glueful\Database\Connection;
use Thallo\Core\Content\Palette\ColorTokenWalker;
use Thallo\Core\Content\Palette\PaletteFence;
use Thallo\Core\Content\Palette\PaletteNormalizer;
use Thallo\Core\Content\Palette\PaletteSnapshot;
use Thallo\Core\Content\Palette\PaletteState;
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
        /** The palette fence (custom palette spec §4.3); null = unfenced (tests, minimal wiring). */
        private readonly ?PaletteFence $fence = null,
        private readonly ?PaletteState $state = null,
        private readonly ?PaletteNormalizer $normalizer = null,
    ) {
        $this->lock = $lock ?? new RegionWriteLock($db);
    }

    /** @var list<array{location: string, from: string, to: string}> */
    private array $rewrites = [];

    /**
     * What the last save's palette normalisation changed (custom palette spec §4.5), for the editor.
     *
     * @return list<array{location: string, from: string, to: string}>
     */
    public function rewrites(): array
    {
        return $this->rewrites;
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
        $this->rewrites = [];
        if ($this->fence === null || $this->state === null || $this->normalizer === null) {
            return $this->saveLocked($posted, $expected, $updatedBy, null);
        }
        // The palette row first, then the region lock (docs/internal/palette-lock-order.md); the regions
        // normalise under both, against the state the row now holds.
        return $this->fence->within(
            fn (): array => $this->saveLocked($posted, $expected, $updatedBy, $this->state?->lock()),
        );
    }

    /**
     * @param array<string, array{blocks?: mixed, settings?: mixed}> $posted
     * @param array<string, ?int> $expected
     * @return array<string, array<string,mixed>>
     */
    private function saveLocked(array $posted, array $expected, ?string $updatedBy, ?PaletteSnapshot $palette): array
    {
        return $this->lock->within(function () use ($posted, $expected, $updatedBy, $palette): array {
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
            if ($palette !== null && $this->normalizer !== null) {
                foreach (array_keys($posted) as $slug) {
                    $doc = ['blocks' => $clean[$slug]['blocks'], 'settings' => $clean[$slug]['settings']];
                    $basis = $this->normalizer->basisOf(ColorTokenWalker::KIND_REGION, null, [
                        'blocks' => $stored[$slug]['blocks'] ?? [],
                        'settings' => $stored[$slug]['settings'] ?? [],
                    ]);
                    $normalized = $this->normalizer->normalize(ColorTokenWalker::KIND_REGION, $doc, $palette, $basis);
                    $clean[$slug]['blocks'] = $normalized->doc['blocks'];
                    $clean[$slug]['settings'] = $normalized->doc['settings'];
                    array_push($this->rewrites, ...$normalized->rewrites);
                    $this->fence?->report($normalized, $palette);
                }
            }

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

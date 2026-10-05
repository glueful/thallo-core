<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Fonts;

use Thallo\Contracts\Fonts\FontFamilyView;
use Thallo\Contracts\Fonts\FontLibrarySnapshotView;

/** The library at one generation, built from one consistent read (FontLibrary::snapshot()). */
final class FontLibrarySnapshot implements FontLibrarySnapshotView
{
    /** @var array<string, FontFamilyView> */
    private readonly array $families;

    /** @param list<FontFamilyView> $families */
    public function __construct(private readonly int $generation, array $families)
    {
        $byId = [];
        foreach ($families as $family) {
            $byId[$family->id] = $family;
        }
        $this->families = $byId;
    }

    public function generation(): int
    {
        return $this->generation;
    }

    public function family(string $id): ?FontFamilyView
    {
        return $this->families[$id] ?? null;
    }

    public function active(): array
    {
        $active = array_values(array_filter($this->families, static fn (FontFamilyView $f): bool => !$f->removed));
        usort($active, static fn (FontFamilyView $a, FontFamilyView $b): int => [strtolower($a->name), $a->id]
            <=> [strtolower($b->name), $b->id]);
        return $active;
    }

    public function resolution(string $id): string
    {
        if (FontId::isReserved($id)) {
            return 'builtin';
        }
        $family = $this->families[$id] ?? null;
        return $family !== null && !$family->removed ? 'uploaded' : 'missing';
    }
}

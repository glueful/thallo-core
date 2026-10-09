<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Palette;

/** A document normalised against the palette (custom palette spec §4.5), and what it changed. */
final class Normalized
{
    /**
     * @param array<string,mixed> $doc
     * @param list<array{location: string, from: string, to: string}> $rewrites the mappings applied
     */
    public function __construct(
        public readonly array $doc,
        public readonly bool $originalHadBrand,
        public readonly bool $normalizedHasBrand,
        public readonly array $rewrites = [],
    ) {
    }

    /** A write is fenced when the submitted or the normalised document names a brand token (§4.3). */
    public function fenced(): bool
    {
        return $this->originalHadBrand || $this->normalizedHasBrand;
    }
}

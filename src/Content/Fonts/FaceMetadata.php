<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Fonts;

/** What one font file covers: its weight range (one weight for a static face) and its style. */
final class FaceMetadata
{
    public function __construct(
        public readonly int $weightMin,
        public readonly int $weightMax,
        public readonly bool $italic,
        public readonly bool $variable,
    ) {
    }
}

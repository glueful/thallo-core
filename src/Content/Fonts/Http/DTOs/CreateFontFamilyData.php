<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Fonts\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/** Request body for `POST /v1/admin/fonts`: a new family from one or more media library `.woff2` files. */
final class CreateFontFamilyData implements RequestData
{
    /** @param list<string> $blob_uuids */
    public function __construct(
        /** @var string The family's display name (never written into CSS). */
        #[Rule('string')]
        public readonly string $name = '',
        /** @var string One of sans-serif, serif, monospace, cursive, system-ui. */
        #[Rule('string')]
        public readonly string $fallback = 'sans-serif',
        /** @var list<string> Media library uuids of the family's `.woff2` files; each is read for its faces. */
        #[Rule('array')]
        public readonly array $blob_uuids = [],
    ) {
    }
}

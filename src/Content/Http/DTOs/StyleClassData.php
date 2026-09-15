<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Request body for `POST /v1/admin/style-classes` (visual builder spec §4.1). `style` is the
 * §1 schema, sparse breakpoints and resets included, validated against every property — a
 * class declares no capabilities. The name is site-unique case-insensitively.
 */
final class StyleClassData implements RequestData
{
    /** @param array<string,mixed> $style */
    public function __construct(
        #[Rule('required|string')]
        public readonly string $name,
        #[Rule('string')]
        public readonly ?string $description = null,
        #[Rule('array')]
        public readonly array $style = [],
    ) {
    }
}

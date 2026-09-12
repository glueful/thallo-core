<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs;

use Glueful\Validation\Attributes\ArrayOf;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Request body for `PATCH /v1/admin/block-types/{slug}`. There is deliberately no
 * `slug` here — slugs are immutable after create (the blocks/{slug}.twig contract).
 */
final class UpdateBlockTypeData implements RequestData
{
    /** @param list<FieldDefinitionData> $schema */
    public function __construct(
        #[Rule('required|string')]
        public readonly string $label,
        /** @var string|null Lucide icon name shown in the block picker. */
        #[Rule('string')]
        public readonly ?string $icon = null,
        /** @var string|null Free-form picker grouping ("Layout", "Content", …); presentation only. */
        #[Rule('string')]
        public readonly ?string $category = null,
        #[Rule('string')]
        public readonly ?string $description = null,
        #[ArrayOf(FieldDefinitionData::class)]
        #[Rule('array')]
        public readonly array $schema = [],
    ) {
    }
}

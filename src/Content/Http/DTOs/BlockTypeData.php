<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs;

use Glueful\Validation\Attributes\ArrayOf;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Request body for `POST /v1/admin/block-types`. Slug shape validates here; the §2
 * block-schema rules (no blocks/localized/filterable inside block schemas) surface
 * from BlockTypeRepository as SchemaParseException → 422. Slugs are immutable after
 * create — the blocks/{slug}.twig template contract.
 */
final class BlockTypeData implements RequestData
{
    /** @param list<FieldDefinitionData> $schema */
    public function __construct(
        /** @var string Unique lowercase block-type slug (also the template name). */
        #[Rule('required|string|regex:/\A[a-z][a-z0-9_-]{0,63}\z/')]
        public readonly string $slug,
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

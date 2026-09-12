<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs\Responses\BlockTypes;

use Thallo\Core\Content\Http\DTOs\Responses\ContentTypes\FieldSchemaData;
use Glueful\Http\Contracts\ResponseData;
use Glueful\Validation\Attributes\ArrayOf;

/**
 * Doc-only schema holder: one block type as the admin API returns it. NEVER
 * constructed at runtime — it exists only so the OpenAPI generator can reflect a
 * typed schema for block-type payloads.
 */
final class BlockTypeItemData implements ResponseData
{
    /** @param list<FieldSchemaData> $schema */
    public function __construct(
        public readonly string $uuid,
        public readonly string $slug,
        public readonly string $label,
        public readonly ?string $icon,
        /** Free-form picker grouping ("Layout", "Content", …); null groups under "Other". */
        public readonly ?string $category,
        public readonly ?string $description,
        public readonly bool $active,
        #[ArrayOf(FieldSchemaData::class)]
        public readonly array $schema = [],
    ) {
    }
}

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
        /** @var list<string>|null Style capability paths or groups (visual builder §1.7); null = none. */
        public readonly ?array $style_capabilities = null,
        /** @var array<string,mixed>|null Named style targets and the capability → target map. */
        public readonly ?array $style_targets = null,
        /** @var array<string,bool>|null `legacy_presentation`, `renders_children_inline`. */
        public readonly ?array $flags = null,
        /** @var array<string,mixed>|null Starter content for a freshly inserted block. */
        public readonly ?array $starter_content = null,
    ) {
    }
}

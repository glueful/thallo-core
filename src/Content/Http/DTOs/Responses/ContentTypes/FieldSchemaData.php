<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs\Responses\ContentTypes;

use Thallo\Core\Content\Enums\FieldType;
use Glueful\Http\Contracts\ResponseData;
use Glueful\Validation\Attributes\ArrayOf;

/**
 * Doc-only schema holder: mirrors one field-schema entry inside the `schema` array the
 * runtime emits on every `content_type` object (see {@see ContentTypeData}).
 * NEVER constructed at runtime — it exists only so the OpenAPI generator can reflect a
 * typed schema for each field definition.
 *
 * Field types are chosen to drive the generated schema, not to match the PHP wire type:
 * `type` is a {@see \Thallo\Core\Content\Enums\FieldType} enum so the reflector emits an
 * `enum:` constraint in the generated spec (the wire value is the backing string).
 * Most optional fields (`required`, `localized`, `filterable`, `filter_type`, `enum`) are
 * absent when falsy — `ContentTypeSchema::toArray()` omits them — so callers should treat
 * all nullable/optional properties as absent-if-falsy rather than always present.
 */
final class FieldSchemaData implements ResponseData
{
    /** @param list<string> $enum */
    public function __construct(
        public readonly string $name,
        public readonly FieldType $type,
        public readonly ?bool $required = null,
        public readonly ?bool $localized = null,
        public readonly ?bool $filterable = null,
        public readonly ?string $filter_type = null,
        #[ArrayOf('string')]
        public readonly array $enum = [],
        /** Presentation widget for text fields: 'plain' (textarea) or 'rich' (editor). */
        public readonly ?string $format = null,
        /** Target content-type slug for a `reference` field (drives the admin picker). */
        public readonly ?string $reference_type = null,
        /** Whether a reference/asset field holds an ordered array of uuids. */
        public readonly ?bool $multiple = null,
        /** Max array length for a multiple field; null = unbounded. */
        public readonly ?int $max_items = null,
        /** Target field used to resolve slug filter values for a reference field (default `slug`). */
        public readonly ?string $reference_slug_field = null,
        /** Allowlist of block-type slugs for a `blocks` field; absent = all active (picker-only
         *  unless enforce_block_types). */
        #[ArrayOf('string')]
        public readonly array $block_types = [],
        /** Opt-in hard server-side enforcement of `block_types`; absent/false = picker-only. */
        public readonly bool $enforce_block_types = false,
        /** Anchored regex body a string/text value must fully match; absent = unconstrained. */
        public readonly ?string $pattern = null,
        /** Inclusive lower bound for a `number` field; absent = unbounded. */
        public readonly ?float $min = null,
        /** Inclusive upper bound for a `number` field; absent = unbounded. */
        public readonly ?float $max = null,
    ) {
    }
}

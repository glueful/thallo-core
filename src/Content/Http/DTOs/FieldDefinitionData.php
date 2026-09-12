<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs;

use Glueful\Validation\Attributes\ArrayOf;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * One field definition inside a content type's `schema` array.
 *
 * Hydrated as the element type of {@see CreateContentTypeData::$schema} /
 * {@see UpdateContentTypeSchemaData::$schema} via `#[ArrayOf(self::class)]`, and reflected
 * into the OpenAPI request-body `items`. Mirrors the *input* shape of the domain object
 * {@see \Thallo\Core\Content\Schema\FieldDefinition} field-for-field (snake_case keys matching the
 * JSON) so the round-trip back to an array via {@see toArray()} loses nothing. The DTO only
 * validates that the structure is well-formed; the semantic schema rules (valid type,
 * `filter_type` required when `filterable`, non-empty `enum`) stay in
 * `FieldDefinition::fromArray()` and surface as a `SchemaParseException` (→ 422).
 */
final class FieldDefinitionData implements RequestData
{
    /** @param list<string> $enum */
    public function __construct(
        #[Rule('required|string')]
        public readonly string $name,
        #[Rule('required|string')]
        public readonly string $type,
        #[Rule('boolean')]
        public readonly ?bool $required = null,
        #[Rule('boolean')]
        public readonly ?bool $localized = null,
        #[Rule('boolean')]
        public readonly ?bool $filterable = null,
        #[Rule('string')]
        public readonly ?string $filter_type = null,
        #[ArrayOf('string')]
        #[Rule('array')]
        public readonly array $enum = [],
        #[Rule('string')]
        public readonly ?string $format = null,
        #[Rule('string')]
        public readonly ?string $reference_type = null,
        #[Rule('boolean')]
        public readonly ?bool $multiple = null,
        #[Rule('numeric')]
        public readonly ?int $max_items = null,
        #[Rule('string')]
        public readonly ?string $reference_slug_field = null,
        /** @var list<string> Block-type allowlist for a `blocks` field (picker-only unless enforce_block_types). */
        #[ArrayOf('string')]
        #[Rule('array')]
        public readonly array $block_types = [],
        /** @var bool Opt-in hard server-side enforcement of `block_types`; default false. */
        #[Rule('boolean')]
        public readonly bool $enforce_block_types = false,
        /** @var string|null Anchored regex body a string/text value must fully match. */
        #[Rule('string')]
        public readonly ?string $pattern = null,
        /** @var float|null Inclusive lower bound for a `number` field (ints coerce). */
        #[Rule('numeric')]
        public readonly ?float $min = null,
        /** @var float|null Inclusive upper bound for a `number` field (ints coerce). */
        #[Rule('numeric')]
        public readonly ?float $max = null,
    ) {
    }

    /**
     * Back to the raw array shape consumed by {@see \Thallo\Core\Content\Schema\FieldDefinition::fromArray()}.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'required' => $this->required ?? false,
            'localized' => $this->localized ?? false,
            'filterable' => $this->filterable ?? false,
            'filter_type' => $this->filter_type,
            'enum' => $this->enum,
            'format' => $this->format,
            'reference_type' => $this->reference_type,
            'multiple' => $this->multiple ?? false,
            'max_items' => $this->max_items,
            'reference_slug_field' => $this->reference_slug_field,
            'block_types' => $this->block_types,
            'enforce_block_types' => $this->enforce_block_types,
            'pattern' => $this->pattern,
            'min' => $this->min,
            'max' => $this->max,
        ];
    }
}

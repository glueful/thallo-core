<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Schema;

use Thallo\Contracts\Schema\ContentSchemaReader;

final class ContentTypeSchema implements ContentSchemaReader
{
    /** @param array<string,FieldDefinition> $byName */
    private function __construct(private readonly array $byName)
    {
    }

    /** @param list<array<string,mixed>> $raw */
    public static function fromArray(array $raw): self
    {
        $byName = [];
        foreach ($raw as $fieldRaw) {
            if (!is_array($fieldRaw)) {
                throw new SchemaParseException('each field definition must be an object');
            }
            $field = FieldDefinition::fromArray($fieldRaw);
            if (isset($byName[$field->name])) {
                throw new SchemaParseException("duplicate field name '{$field->name}'");
            }
            $byName[$field->name] = $field;
        }
        return new self($byName);
    }

    /** @return list<FieldDefinition> */
    public function fields(): array
    {
        return array_values($this->byName);
    }

    public function field(string $name): ?FieldDefinition
    {
        return $this->byName[$name] ?? null;
    }

    /** @return list<array<string,mixed>> normalized form for persistence */
    public function toArray(): array
    {
        return array_map(static fn(FieldDefinition $f): array => array_filter([
            'name' => $f->name,
            'type' => $f->type,
            'required' => $f->required,
            'localized' => $f->localized,
            'filterable' => $f->filterable,
            'filter_type' => $f->filterType,
            'enum' => $f->enumValues,
            'format' => $f->format,
            'reference_type' => $f->referenceType,
            'multiple' => $f->multiple,
            'max_items' => $f->maxItems,
            'reference_slug_field' => $f->type === 'reference' ? $f->referenceSlugField : null,
            'block_types' => $f->blockTypes,
            'enforce_block_types' => $f->enforceBlockTypes,
            'pattern' => $f->pattern,
            'min' => $f->min,
            'max' => $f->max,
        ], static fn($v): bool => $v !== false && $v !== null && $v !== []), $this->fields());
    }
}

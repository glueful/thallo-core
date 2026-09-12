<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Schema\FieldTypes;

use Thallo\Contracts\Schema\FieldTypeDefinition;
use Thallo\Contracts\Schema\FieldTypeRegistry;

/**
 * Registers the core content field types under the "content.*" namespace.
 *
 * Types and capability flags are derived from FieldDefinition::TYPES (the authoritative
 * content-type allowlist) and FieldDefinition::FILTER_TYPES (filterable/sortable scalar
 * types). Membership types (reference, asset) are multi-valued but not filterable or
 * sortable as scalars; text/json are indexable but not filter/sort candidates.
 */
final class EditorialFieldTypes
{
    public static function register(FieldTypeRegistry $registry): void
    {
        foreach (self::definitions() as $definition) {
            $registry->register($definition);
        }
    }

    /** @return list<FieldTypeDefinition> */
    private static function definitions(): array
    {
        return [
            self::make('content.string', 'Text (short)', 'scalar', 'text-input', [
                'filterable' => true,
                'sortable'   => true,
                'indexable'  => true,
                'multi'      => false,
                'localized'  => true,
            ]),
            self::make('content.text', 'Text (long)', 'scalar', 'textarea', [
                'filterable' => false,
                'sortable'   => false,
                'indexable'  => true,
                'multi'      => false,
                'localized'  => true,
            ]),
            self::make('content.number', 'Number', 'scalar', 'number-input', [
                'filterable' => true,
                'sortable'   => true,
                'indexable'  => true,
                'multi'      => false,
                'localized'  => false,
            ]),
            self::make('content.boolean', 'Boolean', 'scalar', 'toggle', [
                'filterable' => true,
                'sortable'   => false,
                'indexable'  => true,
                'multi'      => false,
                'localized'  => false,
            ]),
            self::make('content.datetime', 'Date / Time', 'scalar', 'datetime-input', [
                'filterable' => true,
                'sortable'   => true,
                'indexable'  => true,
                'multi'      => false,
                'localized'  => false,
            ]),
            self::make('content.enum', 'Enum (select)', 'scalar', 'select', [
                'filterable' => true,
                'sortable'   => false,
                'indexable'  => true,
                'multi'      => false,
                'localized'  => false,
            ]),
            self::make('content.reference', 'Reference', 'scalar', 'reference-picker', [
                'filterable' => false,
                'sortable'   => false,
                'indexable'  => false,
                'multi'      => true,
                'localized'  => false,
            ]),
            self::make('content.asset', 'Asset', 'scalar', 'asset-picker', [
                'filterable' => false,
                'sortable'   => false,
                'indexable'  => false,
                'multi'      => true,
                'localized'  => false,
            ]),
            self::make('content.json', 'JSON', 'json', 'json-editor', [
                'filterable' => false,
                'sortable'   => false,
                'indexable'  => false,
                'multi'      => false,
                'localized'  => false,
            ]),
            self::make('content.blocks', 'Blocks', 'json', 'blocks-editor', [
                'filterable' => false,
                'sortable'   => false,
                'indexable'  => false,
                'multi'      => true,
                'localized'  => true,
            ]),
        ];
    }

    /**
     * @param array{filterable:bool,sortable:bool,indexable:bool,multi:bool,localized:bool} $capabilities
     */
    private static function make(
        string $key,
        string $label,
        string $valueShape,
        string $adminWidget,
        array $capabilities,
    ): FieldTypeDefinition {
        return new class ($key, $label, $valueShape, $adminWidget, $capabilities) implements FieldTypeDefinition {
            /**
             * @param array{filterable:bool,sortable:bool,indexable:bool,multi:bool,localized:bool} $caps
             */
            public function __construct(
                private readonly string $key,
                private readonly string $label,
                private readonly string $valueShape,
                private readonly string $adminWidget,
                private readonly array $caps,
            ) {
            }

            public function key(): string
            {
                return $this->key;
            }

            public function label(): string
            {
                return $this->label;
            }

            public function valueShape(): string
            {
                return $this->valueShape;
            }

            public function validationRules(): array
            {
                return [];
            }

            public function adminWidget(): string
            {
                return $this->adminWidget;
            }

            public function capabilities(): array
            {
                return $this->caps;
            }
        };
    }
}

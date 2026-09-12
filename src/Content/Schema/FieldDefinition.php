<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Schema;

use Thallo\Contracts\Schema\FieldDescriptor;

final class FieldDefinition implements FieldDescriptor
{
    public const TYPES = [
        'string', 'text', 'number', 'boolean', 'datetime', 'enum', 'reference', 'asset', 'json', 'blocks',
        // A 4-side spacing/radius box: an object with optional non-negative numeric
        // top/right/bottom/left (px). Renders a linked T/R/B/L editor widget.
        'box',
    ];
    public const FILTER_TYPES = ['string', 'number', 'boolean', 'datetime', 'enum'];
    /** Presentation widget for a `text` field — both store a string; only the editor differs. */
    public const TEXT_FORMATS = ['plain', 'rich'];
    /**
     * Editor-hint formats for STRING fields (icon-picker spec §2): presentation
     * metadata only — validation stays with the field's pattern/enum rules.
     * 'color' renders a swatch + hex picker (pair with a HEX `pattern`).
     */
    public const STRING_FORMATS = ['icon', 'brand-icon', 'color'];

    /** @param list<string> $enumValues */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly bool $required = false,
        public readonly bool $localized = false,
        public readonly bool $filterable = false,
        public readonly ?string $filterType = null,
        public readonly array $enumValues = [],
        /** 'plain'|'rich' for text fields; null for every other type. */
        public readonly ?string $format = null,
        /** Target content-type slug for a `reference` field (drives the admin picker); null otherwise. */
        public readonly ?string $referenceType = null,
        /** Whether the field accepts multiple values (reference and asset fields only). */
        public readonly bool $multiple = false,
        /** Maximum number of selected items when `multiple` is true; null means unlimited. */
        public readonly ?int $maxItems = null,
        /** Field name on the referenced entry used as its slug identifier; reference fields only. */
        public readonly ?string $referenceSlugField = null,
        /**
         * Allowlist of block-type slugs for a `blocks` field ([] = all active).
         * Picker-only BY DEFAULT (block-builder spec §1) — the admin UI offers
         * only these types, but FieldValidator does not reject others unless
         * `enforceBlockTypes` opts into hard server-side enforcement.
         *
         * @var list<string>
         */
        public readonly array $blockTypes = [],
        /**
         * Opt-in hard server-side enforcement of `blockTypes` (default false preserves the
         * historical picker-only behavior for every existing field). Valid only on `blocks`
         * fields; ignored/false for every other type. See {@see \Thallo\Core\Content\Validation\
         * FieldValidator::validateBlocks()}.
         */
        public readonly bool $enforceBlockTypes = false,
        /**
         * Anchored regex BODY (no delimiters) a string/text value must fully match;
         * null = unconstrained. Generic value validation (block-library spec §5) —
         * e.g. the container block's hex colors, the shortcode block's name.
         */
        public readonly ?string $pattern = null,
        /** Inclusive lower bound for a `number` field; null = unbounded. */
        public readonly int|float|null $min = null,
        /** Inclusive upper bound for a `number` field; null = unbounded. */
        public readonly int|float|null $max = null,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function isMultiple(): bool
    {
        return $this->multiple;
    }

    public function referenceType(): ?string
    {
        return $this->referenceType;
    }

    public function referenceSlugField(): ?string
    {
        return $this->referenceSlugField;
    }

    public function format(): ?string
    {
        return $this->format;
    }

    /** @param array<string,mixed> $raw */
    public static function fromArray(array $raw): self
    {
        $name = isset($raw['name']) && is_string($raw['name']) ? $raw['name'] : '';
        if ($name === '' || preg_match('/\A[a-z][a-z0-9_]*\z/', $name) !== 1) {
            throw new SchemaParseException("field name must match [a-z][a-z0-9_]*: '{$name}'");
        }
        $type = $raw['type'] ?? null;
        if (!is_string($type) || !in_array($type, self::TYPES, true)) {
            throw new SchemaParseException("field '{$name}' has invalid type");
        }
        $filterable = (bool) ($raw['filterable'] ?? false);
        $filterType = $raw['filter_type'] ?? null;
        $membershipType = in_array($type, ['reference', 'asset'], true);
        if ($filterable && !$membershipType) {
            if (!is_string($filterType) || !in_array($filterType, self::FILTER_TYPES, true)) {
                throw new SchemaParseException("filterable field '{$name}' must declare a valid filter_type");
            }
        } else {
            // membership fields (reference/asset) carry no scalar filter_type
            $filterType = null;
        }
        $enum = [];
        if ($type === 'enum') {
            $enum = array_values(array_filter(
                array_map('strval', (array) ($raw['enum'] ?? [])),
                static fn(string $v): bool => $v !== ''
            ));
            if ($enum === []) {
                throw new SchemaParseException("enum field '{$name}' requires non-empty enum values");
            }
        }
        // `format` is a presentation hint with a per-type vocabulary: text fields
        // pick their editor (plain textarea vs rich, default 'plain'); string
        // fields may declare an icon picker (icon|brand-icon, default none —
        // icon-picker spec §2; validation stays with pattern/enum). Unknown
        // values fail LOUDLY for typed vocabularies; other types ignore format.
        $format = null;
        if ($type === 'text') {
            $rawFormat = $raw['format'] ?? null;
            if ($rawFormat === null || $rawFormat === '') {
                $format = 'plain';
            } elseif (is_string($rawFormat) && in_array($rawFormat, self::TEXT_FORMATS, true)) {
                $format = $rawFormat;
            } else {
                throw new SchemaParseException("text field '{$name}' has invalid format (expected plain|rich)");
            }
        } elseif ($type === 'string') {
            $rawFormat = $raw['format'] ?? null;
            if ($rawFormat !== null && $rawFormat !== '') {
                if (!is_string($rawFormat) || !in_array($rawFormat, self::STRING_FORMATS, true)) {
                    throw new SchemaParseException(
                        "string field '{$name}' has invalid format (expected icon|brand-icon|color)"
                    );
                }
                $format = $rawFormat;
            }
        }

        // `reference_type` names the target content type for a `reference` field (drives the admin's
        // reference picker). Only meaningful for reference fields; ignored for every other type.
        // `reference_slug_field` is the field on the referenced entry that carries its slug;
        // defaults to 'slug' and must match [a-z][a-z0-9_]*.
        $referenceType = null;
        $referenceSlugField = null;
        if ($type === 'reference') {
            $rawRef = $raw['reference_type'] ?? null;
            if (is_string($rawRef) && $rawRef !== '') {
                $referenceType = $rawRef;
            }
            $rawSlug = $raw['reference_slug_field'] ?? null;
            $referenceSlugField = is_string($rawSlug) && $rawSlug !== '' ? $rawSlug : 'slug';
            if (preg_match('/\A[a-z][a-z0-9_]*\z/', $referenceSlugField) !== 1) {
                throw new SchemaParseException("field '{$name}' has invalid reference_slug_field");
            }
        }

        // `multiple` and `max_items` apply to reference and asset fields only.
        $multiple = false;
        $maxItems = null;
        if ($type === 'reference' || $type === 'asset') {
            $multiple = (bool) ($raw['multiple'] ?? false);
            if (array_key_exists('max_items', $raw) && $raw['max_items'] !== null) {
                $mi = $raw['max_items'];
                if (!is_int($mi) || $mi < 1) {
                    throw new SchemaParseException("field '{$name}' max_items must be a positive integer");
                }
                $maxItems = $mi;
            }
        }

        // `blocks` (block-builder spec §1): block_types is a picker-only allowlist by
        // default — FieldValidator does not enforce it unless enforce_block_types opts
        // in (tightening the DEFAULT must never strand existing content). Blocks fields
        // are never filterable.
        $blockTypes = [];
        $enforceBlockTypes = false;
        if ($type === 'blocks') {
            if ($filterable) {
                throw new SchemaParseException("blocks field '{$name}' cannot be filterable");
            }
            $blockTypes = array_values(array_filter(
                array_map('strval', (array) ($raw['block_types'] ?? [])),
                static fn(string $v): bool => $v !== ''
            ));
            $enforceBlockTypes = (bool) ($raw['enforce_block_types'] ?? false);
        }

        // Generic value constraints (block-library spec §5): `pattern` on
        // string/text (anchored regex body, compilability checked HERE so a bad
        // regex fails at schema save, never at content save), `min`/`max` on
        // number. Ignored on other types.
        $pattern = null;
        if (($type === 'string' || $type === 'text') && is_string($raw['pattern'] ?? null) && $raw['pattern'] !== '') {
            $pattern = (string) $raw['pattern'];
            if (@preg_match('/\A(?:' . str_replace('/', '\/', $pattern) . ')\z/u', '') === false) {
                throw new SchemaParseException("field '{$name}' has an invalid pattern regex");
            }
        }
        $min = null;
        $max = null;
        if ($type === 'number') {
            foreach (['min', 'max'] as $bound) {
                $rawBound = $raw[$bound] ?? null;
                if ($rawBound !== null) {
                    if (!is_int($rawBound) && !is_float($rawBound)) {
                        throw new SchemaParseException("field '{$name}' {$bound} must be a number");
                    }
                    ${$bound} = $rawBound;
                }
            }
            if ($min !== null && $max !== null && $min > $max) {
                throw new SchemaParseException("field '{$name}' min must not exceed max");
            }
        }

        return new self(
            name: $name,
            type: $type,
            required: (bool) ($raw['required'] ?? false),
            localized: (bool) ($raw['localized'] ?? false),
            filterable: $filterable,
            filterType: $filterType,
            enumValues: $enum,
            format: $format,
            referenceType: $referenceType,
            multiple: $multiple,
            maxItems: $maxItems,
            referenceSlugField: $referenceSlugField,
            blockTypes: $blockTypes,
            enforceBlockTypes: $enforceBlockTypes,
            pattern: $pattern,
            min: $min,
            max: $max,
        );
    }
}

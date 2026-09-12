<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Regions;

use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Content\Validation\FieldValidator;
use Thallo\Core\Content\Validation\ValidationException;

/**
 * Region save validation (global-regions spec §4/§6): the blocks list runs the
 * REAL FieldValidator (block schemas, depth cap, id uniqueness) through a
 * synthetic one-field schema, then the palette is enforced on TOP-LEVEL types
 * only — nested blocks-fields inside an allowed block are governed by that
 * block's own schema, same as entries. The palette check runs FIRST so an
 * out-of-palette block yields the product error, not a schema error for a
 * block that was never allowed. Settings mirror validatePresentation: a fixed
 * vocabulary that fails loudly.
 */
final class RegionValidator
{
    public function __construct(private readonly FieldValidator $fields)
    {
    }

    /**
     * @param list<array<string,mixed>> $blocks
     * @param array<string,mixed> $settings
     * @return array{blocks: list<array<string,mixed>>, settings: array<string,mixed>}
     * @throws ValidationException
     */
    public function validate(string $slug, array $blocks, array $settings): array
    {
        $palette = RegionDefinitions::PALETTES[$slug] ?? null;
        if ($palette === null) {
            throw new ValidationException(['slug' => "unknown region '{$slug}'"]);
        }

        $errors = [];
        foreach (array_values($blocks) as $i => $block) {
            $type = is_array($block) ? ($block['type'] ?? null) : null;
            if (!is_string($type) || !in_array($type, $palette, true)) {
                $label = is_string($type) ? $type : '?';
                $errors["blocks.{$i}.type"] = "'{$label}' is not allowed in the {$slug} region";
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $schema = ContentTypeSchema::fromArray([['name' => 'blocks', 'type' => 'blocks']]);
        $clean = $this->fields->validate($schema, ['blocks' => array_values($blocks)], true);

        return [
            'blocks' => $clean['blocks'] ?? [],
            'settings' => $this->validateSettings($slug, $settings),
        ];
    }

    /**
     * @param array<string,mixed> $settings
     * @return array<string,mixed>
     */
    private function validateSettings(string $slug, array $settings): array
    {
        $allowed = RegionDefinitions::SETTINGS_KEYS[$slug] ?? [];
        $clean = [];
        foreach ($settings as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                throw new ValidationException(["settings.{$key}" => 'unknown setting for this region']);
            }
            if ($key === 'sticky') {
                if (!is_bool($value)) {
                    throw new ValidationException(['settings.sticky' => 'must be a boolean']);
                }
                $clean['sticky'] = $value;
            }
            if ($key === 'width') {
                if (!in_array($value, ['contained', 'full'], true)) {
                    throw new ValidationException(['settings.width' => "must be 'contained' or 'full'"]);
                }
                $clean['width'] = $value;
            }
        }
        return $clean;
    }
}

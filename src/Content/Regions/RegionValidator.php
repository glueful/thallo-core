<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Regions;

use Thallo\Contracts\Style\RegionStyle;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Content\Style\SettingsValidator;
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
    public function __construct(
        private readonly FieldValidator $fields,
        private readonly SettingsValidator $settingsValidator = new SettingsValidator(),
    ) {
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
     * Both regions as one candidate (regions-stage spec §4.3, §4.5): each region validated as a
     * save would validate it, then block ids checked across the two — the stage's DOM↔id bridge
     * needs every id unique over the header AND the footer. Errors are prefixed `regions.{slug}.`
     * and the cross-region collision is reported on the footer's block.
     *
     * @param array<string, array{blocks?: mixed, settings?: mixed}> $regions keyed by slug
     * @return array<string, array{blocks: list<array<string,mixed>>, settings: array<string,mixed>}>
     * @throws ValidationException
     */
    public function validateBoth(array $regions): array
    {
        $errors = [];
        $clean = [];
        foreach (RegionDefinitions::slugs() as $slug) {
            $region = $regions[$slug] ?? ['blocks' => [], 'settings' => []];
            $blocks = is_array($region['blocks'] ?? null) ? array_values($region['blocks']) : [];
            $settings = is_array($region['settings'] ?? null) ? $region['settings'] : [];
            try {
                $clean[$slug] = $this->validate($slug, $blocks, $settings);
            } catch (ValidationException $e) {
                foreach ($e->errors() as $path => $message) {
                    $errors["regions.{$slug}.{$path}"] = $message;
                }
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $seen = [];
        foreach (RegionDefinitions::slugs() as $slug) {
            foreach (self::ids($clean[$slug]['blocks']) as $path => $id) {
                if (isset($seen[$id]) && $seen[$id] !== $slug) {
                    $errors["regions.{$slug}.blocks.{$path}.id"] = "id '{$id}' is already used in the {$seen[$id]}";
                }
                $seen[$id] ??= $slug;
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        return $clean;
    }

    /**
     * Every block id in a list and the blocks nested in it, keyed by a dot path.
     *
     * @param list<array<string,mixed>> $blocks
     * @return array<string,string>
     */
    private static function ids(array $blocks, string $prefix = ''): array
    {
        $out = [];
        foreach ($blocks as $i => $block) {
            if (!is_array($block)) {
                continue;
            }
            $path = $prefix . $i;
            if (is_string($block['id'] ?? null)) {
                $out[$path] = $block['id'];
            }
            foreach (is_array($block['data'] ?? null) ? $block['data'] : [] as $field => $value) {
                if (is_array($value) && array_is_list($value) && isset($value[0]['type'])) {
                    $out += self::ids($value, "{$path}.data.{$field}.");
                }
            }
        }
        return $out;
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
            // The region's Style tab: the block style record, held to what a region may be
            // styled with. The style validator's errors are already `settings.style…` paths.
            if ($key === 'style') {
                [$styled, $errors] = $this->settingsValidator->validate(
                    ['style' => $value],
                    RegionStyle::capabilities(),
                );
                if ($errors !== []) {
                    throw new ValidationException($errors);
                }
                if (isset($styled['style'])) {
                    $clean['style'] = $styled['style'];
                }
            }
        }
        return $clean;
    }
}

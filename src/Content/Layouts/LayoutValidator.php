<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Layouts;

use Thallo\Contracts\Layouts\LayoutSurface;
use Thallo\Contracts\Layouts\LayoutSurfaceRegistry;
use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Content\Style\Classes\StyleClassReferenceGuard;
use Thallo\Core\Content\Validation\FieldValidator;
use Thallo\Core\Content\Validation\ValidationException;

/**
 * Layout validation on apply and save (type layouts spec §5.6). A layout holds the general content
 * blocks and its surface's field blocks, anywhere in its tree; its blocks validate as a page save
 * would; what the surface requires is placed exactly once and each blocks field at most once; and
 * every field a block names exists on the target with a type that block can show. The frame
 * settings are a fixed vocabulary. Errors name the block and its field (`blocks.3.data.field`).
 */
final class LayoutValidator
{
    /** Field block => the field types it can show (`text:rich` is a rich text field). */
    private const BINDINGS = [
        'entry_cover' => ['asset'],
        'entry_terms' => ['reference'],
        'entry_excerpt' => ['string', 'text'],
        'entry_field' => ['string', 'text', 'text:rich', 'number', 'boolean', 'datetime', 'enum'],
        'entry_content' => ['blocks'],
    ];

    /**
     * The field a block shows when none is chosen: its template's default. Validation writes it
     * into the block where the type has that field (and the block can show it), so every binding a
     * layout relies on is explicit — a rename moves it and a delete is refused (spec §5.7).
     */
    private const DEFAULT_FIELD = [
        'entry_content' => 'body',
        'entry_cover' => 'cover',
        'entry_excerpt' => 'excerpt',
        'entry_terms' => 'categories',
    ];

    /** Entry field formats that need a field of their kind: a date read from a word fails the page. */
    private const FORMAT_NEEDS = ['date' => ['datetime'], 'number' => ['number']];

    private const SETTINGS = [
        'width' => ['contained', 'full'],
        'header' => ['default', 'hidden'],
        'footer' => ['default', 'hidden'],
    ];

    public function __construct(
        private readonly FieldValidator $fields,
        private readonly LayoutSurfaceRegistry $surfaces,
        private readonly BlockTypeRepository $blockTypes,
        /** Style classes a save introduces are checked at the write (visual builder spec §4.5); null = unchecked. */
        private readonly ?StyleClassReferenceGuard $guard = null,
    ) {
    }

    /**
     * @param list<array<string,mixed>> $blocks
     * @param array<string,mixed> $settings
     * @param list<array<string,mixed>> $before the stored layout's blocks: style classes they already
     *        reference are not re-checked, so a class archived since never blocks an edit elsewhere
     * @param bool $guard run the style-class reference guard — a write that serialises against a
     *        class job, so Save's alone (inside its lock); an apply, which persists nothing, passes false
     * @return array{blocks: list<array<string,mixed>>, settings: array<string,mixed>}
     * @throws ValidationException
     */
    public function validate(
        string $surface,
        string $target,
        array $blocks,
        array $settings,
        array $before = [],
        bool $guard = true,
    ): array {
        $kind = $this->surfaces->get($surface);
        if ($kind === null) {
            throw new ValidationException(['surface' => "unknown layout surface '{$surface}'"]);
        }
        if (!self::isTarget($kind, $target)) {
            throw new ValidationException(['target' => "'{$target}' cannot have a layout"]);
        }
        $cleanSettings = self::settings($settings);

        $allowed = $this->allowedTypes($kind);
        $errors = [];
        foreach (self::walk(array_values($blocks)) as $path => $block) {
            $type = $block['type'] ?? null;
            if (!is_string($type) || !isset($allowed[$type])) {
                $label = is_string($type) ? $type : '?';
                $errors["{$path}.type"] = "'{$label}' cannot be placed in a layout";
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $schema = ContentTypeSchema::fromArray([['name' => 'blocks', 'type' => 'blocks']]);
        $clean = $this->fields->forLayouts()->validate($schema, ['blocks' => array_values($blocks)], true);
        $cleanBlocks = self::bindDefaults(array_values($clean['blocks'] ?? []), $kind->bindable($target));

        $this->assertBindings($kind, $target, $cleanBlocks);
        if ($guard) {
            $this->guard?->assertBlocksWritable($before, $cleanBlocks);
        }

        return ['blocks' => $cleanBlocks, 'settings' => $cleanSettings];
    }

    /**
     * Every field a block names exists with a type it can show; each blocks field is placed at most
     * once; what the surface requires is placed.
     *
     * @param list<array<string,mixed>> $blocks
     */
    private function assertBindings(LayoutSurface $kind, string $target, array $blocks): void
    {
        $bindable = $kind->bindable($target);
        $errors = [];
        $placed = [];
        foreach (self::walk($blocks) as $path => $block) {
            $type = (string) $block['type'];
            if (!isset(self::BINDINGS[$type])) {
                continue;
            }
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            // bindDefaults() has bound every default the type has; a block still without a field shows
            // nothing — except Entry content, whose `body` the slot count still needs to judge.
            $field = is_string($data['field'] ?? null) && $data['field'] !== ''
                ? $data['field']
                : ($type === 'entry_content' ? 'body' : null);
            if ($field === null) {
                continue;
            }
            $fieldType = $bindable[$field] ?? null;
            if ($fieldType === null) {
                $errors["{$path}.data.field"] = "this type has no field '{$field}'";
                continue;
            }
            if (!in_array($fieldType, self::BINDINGS[$type], true)) {
                $errors["{$path}.data.field"] = "'{$field}' cannot be shown by this block";
                continue;
            }
            $format = is_string($data['format'] ?? null) ? $data['format'] : null;
            $needs = $type === 'entry_field' && $format !== null ? (self::FORMAT_NEEDS[$format] ?? null) : null;
            if ($needs !== null && !in_array($fieldType, $needs, true)) {
                $errors["{$path}.data.format"] = "'{$field}' cannot be shown as a {$format}";
                continue;
            }
            if ($type === 'entry_content') {
                if (isset($placed[$field])) {
                    $errors["{$path}.data.field"] = "'{$field}' is already placed by another block";
                    continue;
                }
                $placed[$field] = true;
            }
        }
        foreach ($kind->required($target) as $required) {
            $field = $required['field'] ?? null;
            if ($required['type'] === 'entry_content' && is_string($field) && !isset($placed[$field])) {
                $errors['blocks'] ??= "the layout must show the '{$field}' field once, with an Entry content block";
            }
            if ($field === null) {
                $errors += $this->placedOnce($required['type'], $blocks);
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
    }

    /**
     * A required block without a field (the product page's Product buy box) is placed exactly once,
     * anywhere in the tree: missing, the error names it by its label; twice, the second is refused.
     *
     * @param list<array<string,mixed>> $blocks
     * @return array<string,string>
     */
    private function placedOnce(string $type, array $blocks): array
    {
        $seen = false;
        foreach (self::walk($blocks) as $path => $block) {
            if (($block['type'] ?? null) !== $type) {
                continue;
            }
            if ($seen) {
                return ["{$path}.type" => "'{$type}' can appear only once in a layout"];
            }
            $seen = true;
        }
        if ($seen) {
            return [];
        }
        $label = $type;
        foreach ($this->blockTypes->all() as $row) {
            if (($row['slug'] ?? null) === $type && is_string($row['label'] ?? null)) {
                $label = $row['label'];
            }
        }
        return ['blocks' => "the layout must show the {$label} block"];
    }

    /**
     * Write the default field into every field block left without one, where the type has that
     * field and the block can show it; elsewhere leave the block unbound (it shows nothing).
     *
     * @param list<mixed> $blocks
     * @param array<string,string> $bindable field name => field type
     * @return list<mixed>
     */
    private static function bindDefaults(array $blocks, array $bindable): array
    {
        foreach ($blocks as $i => $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? '');
            $data = is_array($block['data'] ?? null) ? $block['data'] : [];
            $default = self::DEFAULT_FIELD[$type] ?? null;
            $chosen = is_string($data['field'] ?? null) && $data['field'] !== '';
            if (
                $default !== null && !$chosen && isset($bindable[$default])
                && in_array($bindable[$default], self::BINDINGS[$type], true)
            ) {
                $blocks[$i]['data'] = ['field' => $default] + $data;
            }
            foreach ($data as $key => $value) {
                if (is_array($value) && array_is_list($value) && isset($value[0]['type'])) {
                    $blocks[$i]['data'][$key] = self::bindDefaults($value, $bindable);
                }
            }
        }
        return $blocks;
    }

    /** @return array<string,true> the general content blocks and the surface's field blocks */
    private function allowedTypes(LayoutSurface $kind): array
    {
        $allowed = [];
        foreach ($this->blockTypes->all() as $row) {
            $flags = is_array($row['flags'] ?? null) ? $row['flags'] : [];
            if ((bool) ($row['active'] ?? false) && ($flags['layout_only'] ?? false) !== true) {
                $allowed[(string) $row['slug']] = true;
            }
        }
        foreach ($kind->palette() as $slug) {
            $allowed[$slug] = true;
        }
        return $allowed;
    }

    private static function isTarget(LayoutSurface $kind, string $target): bool
    {
        foreach ($kind->targets() as $row) {
            if ($row['target'] === $target) {
                return $row['enabled'];
            }
        }
        return false;
    }

    /**
     * @param array<string,mixed> $settings
     * @return array<string,string>
     */
    private static function settings(array $settings): array
    {
        $clean = [];
        foreach ($settings as $key => $value) {
            $allowed = self::SETTINGS[$key] ?? null;
            if ($allowed === null) {
                throw new ValidationException(["settings.{$key}" => 'unknown frame setting']);
            }
            if (!in_array($value, $allowed, true)) {
                throw new ValidationException(["settings.{$key}" => "must be '" . implode("' or '", $allowed) . "'"]);
            }
            $clean[$key] = $value;
        }
        return $clean;
    }

    /**
     * Every block in a list and the blocks nested in it, keyed by its dot path.
     *
     * @param list<mixed> $blocks
     * @return \Generator<string, array<string,mixed>>
     */
    private static function walk(array $blocks, string $prefix = 'blocks.'): \Generator
    {
        foreach ($blocks as $i => $block) {
            if (!is_array($block)) {
                yield $prefix . $i => [];
                continue;
            }
            yield $prefix . $i => $block;
            foreach (is_array($block['data'] ?? null) ? $block['data'] : [] as $field => $value) {
                if (is_array($value) && array_is_list($value) && isset($value[0]['type'])) {
                    yield from self::walk($value, "{$prefix}{$i}.data.{$field}.");
                }
            }
        }
    }
}

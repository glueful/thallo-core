<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Style;

use Thallo\Contracts\Style\PropertyDefinition;
use Thallo\Contracts\Style\StyleCapabilities;
use Thallo\Contracts\Style\StyleSchema;
use Thallo\Contracts\Style\ValueKind;
use Thallo\Contracts\Style\Vocabulary;

/**
 * Validates one block's `settings` (visual builder spec §1.1–1.5) against the style contract and
 * the block's capabilities. Returns the normalised settings and errors keyed by a path relative
 * to the block (`settings.style.spacing.padding.top`). While a block type still carries
 * `legacy_presentation`, any managed style is refused so a setting never competes with a legacy
 * field; `advanced` and `classes` are allowed regardless.
 */
final class SettingsValidator
{
    private const SLUG = '/\A[a-z][a-z0-9-]{0,63}\z/';
    private const CLASS_NAME = '/\A-?[_a-zA-Z][_a-zA-Z0-9-]{0,127}\z/';
    private const DATA_ATTR = '/\Adata-[a-z][a-z0-9-]{0,63}\z/';
    private const RESERVED_ATTR_PREFIX = 'data-thallo-';

    /**
     * @return array{0: array<string,mixed>, 1: array<string,string>}
     */
    public function validate(mixed $settings, StyleCapabilities $caps, bool $legacyPresentation): array
    {
        if ($settings === null || $settings === []) {
            return [[], []];
        }
        if (!is_array($settings) || array_is_list($settings)) {
            return [[], ['settings' => 'must be an object']];
        }
        $clean = [];
        $errors = [];
        foreach ($settings as $key => $value) {
            switch ($key) {
                case 'style':
                    if ($value === null || $value === []) {
                        break;
                    }
                    if ($legacyPresentation) {
                        $errors['settings.style'] = 'styling for this block arrives with its conversion';
                        break;
                    }
                    [$style, $styleErrors] = $this->validateStyle($value, $caps);
                    $errors += $styleErrors;
                    if ($style !== []) {
                        $clean['style'] = $style;
                    }
                    break;
                case 'classes':
                    [$classes, $classErrors] = $this->validateClasses($value);
                    $errors += $classErrors;
                    if ($classes !== []) {
                        $clean['classes'] = $classes;
                    }
                    break;
                case 'advanced':
                    [$advanced, $advancedErrors] = $this->validateAdvanced($value);
                    $errors += $advancedErrors;
                    if ($advanced !== []) {
                        $clean['advanced'] = $advanced;
                    }
                    break;
                default:
                    $errors["settings.{$key}"] = 'unknown settings key';
            }
        }
        return [$clean, $errors];
    }

    /**
     * @return array{0: array<string,mixed>, 1: array<string,string>}
     */
    private function validateStyle(mixed $style, StyleCapabilities $caps): array
    {
        if (!is_array($style) || array_is_list($style)) {
            return [[], ['settings.style' => 'must be an object']];
        }
        $clean = [];
        $errors = [];
        foreach ($this->flatten($style) as $path => $value) {
            $def = StyleSchema::property($path);
            if ($def === null) {
                $errors["settings.style.{$path}"] = 'unknown style property';
                continue;
            }
            if (!$caps->allows($path)) {
                $errors["settings.style.{$path}"] = 'not styleable on this block';
                continue;
            }
            [$cleanValue, $error, $breakpoint] = $def->responsive
                ? $this->validateResponsive($def, $value)
                : [...$this->validateSingle($def, $value), null];
            if ($error !== null) {
                $errors['settings.style.' . $path . ($breakpoint === null ? '' : ".{$breakpoint}")] = $error;
                continue;
            }
            if ($cleanValue !== null) {
                $this->set($clean, $path, $cleanValue);
            }
        }
        return [$clean, $errors];
    }

    /**
     * Walk nested groups down to property paths; a known property path, a scalar or a list
     * stops the walk (values are examined by the property validators).
     *
     * @param array<string,mixed> $node
     * @return array<string, mixed>
     */
    private function flatten(array $node, string $prefix = ''): array
    {
        $out = [];
        foreach ($node as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
            $isLeaf = StyleSchema::property($path) !== null
                || !is_array($value)
                || array_is_list($value)
                || isset($value['type'])
                || array_intersect(array_keys($value), StyleSchema::BREAKPOINTS) !== [];
            if ($isLeaf) {
                $out[$path] = $value;
                continue;
            }
            $out += $this->flatten($value, $path);
        }
        return $out;
    }

    /**
     * A sparse breakpoint map; an error names the breakpoint it sits at (null for the map itself).
     *
     * @return array{0: mixed, 1: ?string, 2: ?string}
     */
    private function validateResponsive(PropertyDefinition $def, mixed $value): array
    {
        if (!is_array($value) || array_is_list($value) || isset($value['type'])) {
            return [null, 'must be a breakpoint map {base, md, lg}', null];
        }
        $clean = [];
        foreach ($value as $bp => $v) {
            if (!in_array($bp, StyleSchema::BREAKPOINTS, true)) {
                return [null, sprintf('unknown breakpoint "%s"', (string) $bp), null];
            }
            [$cleanValue, $error] = $this->validateSingle($def, $v);
            if ($error !== null) {
                return [null, $error, (string) $bp];
            }
            if ($cleanValue !== null) {
                $clean[$bp] = $cleanValue;
            }
        }
        $ordered = [];
        foreach (StyleSchema::BREAKPOINTS as $bp) {
            if (isset($clean[$bp])) {
                $ordered[$bp] = $clean[$bp];
            }
        }
        return [$ordered === [] ? null : $ordered, null, null];
    }

    /** @return array{0: ?array<string,mixed>, 1: ?string} */
    private function validateSingle(PropertyDefinition $def, mixed $value): array
    {
        if ($value === null) {
            return [null, null];
        }
        if (!is_array($value) || !isset($value['type']) || !is_string($value['type'])) {
            if (is_array($value) && !array_is_list($value) && !$def->responsive) {
                return [null, 'is not responsive'];
            }
            return [null, 'must be a typed value {type, value}'];
        }
        $kind = ValueKind::tryFrom($value['type']);
        if ($kind === ValueKind::Literal) {
            return [null, 'reserved value kind "literal"'];
        }
        if ($kind === null) {
            return [null, sprintf('unknown value kind "%s"', $value['type'])];
        }
        if (!$def->accepts($kind)) {
            return [null, sprintf('expects a %s', $def->tokenDomain !== null ? 'token' : 'choice')];
        }
        if ($kind === ValueKind::Reset) {
            return [['type' => 'reset'], null];
        }
        $raw = $value['value'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return [null, 'needs a value'];
        }
        if ($kind === ValueKind::Token) {
            if (!Vocabulary::isBaseline($raw)) {
                return [null, sprintf('unknown token "%s"', $raw)];
            }
            if (Vocabulary::domain($raw) !== $def->tokenDomain) {
                return [null, sprintf('expects a %s token', (string) $def->tokenDomain)];
            }
            return [['type' => 'token', 'value' => $raw], null];
        }
        if (!in_array($raw, $def->choices ?? [], true)) {
            return [null, 'must be one of ' . implode(', ', $def->choices ?? [])];
        }
        return [['type' => 'choice', 'value' => $raw], null];
    }

    /** @return array{0: list<string>, 1: array<string,string>} */
    private function validateClasses(mixed $value): array
    {
        if ($value === null) {
            return [[], []];
        }
        if (!is_array($value) || !array_is_list($value)) {
            return [[], ['settings.classes' => 'must be a list of style class ids']];
        }
        $clean = [];
        $errors = [];
        foreach ($value as $i => $id) {
            if (!is_string($id) || $id === '') {
                $errors["settings.classes.{$i}"] = 'must be a style class id';
                continue;
            }
            $clean[] = $id;
        }
        return [$clean, $errors];
    }

    /** @return array{0: array<string,mixed>, 1: array<string,string>} */
    private function validateAdvanced(mixed $value): array
    {
        if ($value === null || $value === []) {
            return [[], []];
        }
        if (!is_array($value) || array_is_list($value)) {
            return [[], ['settings.advanced' => 'must be an object']];
        }
        $clean = [];
        $errors = [];
        foreach ($value as $key => $v) {
            switch ($key) {
                case 'anchor':
                    if ($v === null || $v === '') {
                        break;
                    }
                    if (!is_string($v) || preg_match(self::SLUG, $v) !== 1) {
                        $errors['settings.advanced.anchor'] = 'must be a slug ([a-z][a-z0-9-]*)';
                        break;
                    }
                    $clean['anchor'] = $v;
                    break;
                case 'css_classes':
                    if (!is_array($v) || !array_is_list($v)) {
                        $errors['settings.advanced.css_classes'] = 'must be a list of class names';
                        break;
                    }
                    $names = [];
                    foreach ($v as $i => $name) {
                        if (!is_string($name) || preg_match(self::CLASS_NAME, $name) !== 1) {
                            $errors["settings.advanced.css_classes.{$i}"] = 'must be a class name';
                            continue;
                        }
                        $names[] = $name;
                    }
                    if ($names !== []) {
                        $clean['css_classes'] = $names;
                    }
                    break;
                case 'attributes':
                    if (!is_array($v) || array_is_list($v)) {
                        $errors['settings.advanced.attributes'] = 'must be an object of data-* attributes';
                        break;
                    }
                    $attrs = [];
                    foreach ($v as $name => $attrValue) {
                        $name = (string) $name;
                        if (str_starts_with($name, self::RESERVED_ATTR_PREFIX)) {
                            $errors["settings.advanced.attributes.{$name}"] = 'reserved prefix data-thallo-';
                            continue;
                        }
                        if (preg_match(self::DATA_ATTR, $name) !== 1) {
                            $errors["settings.advanced.attributes.{$name}"] = 'must be a data-* name';
                            continue;
                        }
                        if (!is_string($attrValue) || strlen($attrValue) > 255) {
                            $errors["settings.advanced.attributes.{$name}"] =
                                'must be a string of at most 255 characters';
                            continue;
                        }
                        $attrs[$name] = $attrValue;
                    }
                    if ($attrs !== []) {
                        $clean['attributes'] = $attrs;
                    }
                    break;
                case 'accessibility':
                    if (!is_array($v) || array_is_list($v)) {
                        $errors['settings.advanced.accessibility'] = 'must be an object';
                        break;
                    }
                    foreach ($v as $aKey => $aValue) {
                        if ($aKey !== 'label') {
                            $errors["settings.advanced.accessibility.{$aKey}"] = 'unknown accessibility key';
                            continue;
                        }
                        if ($aValue === null || $aValue === '') {
                            continue;
                        }
                        if (!is_string($aValue) || strlen($aValue) > 255) {
                            $errors['settings.advanced.accessibility.label'] =
                                'must be a string of at most 255 characters';
                            continue;
                        }
                        $clean['accessibility'] = ['label' => $aValue];
                    }
                    break;
                default:
                    $errors["settings.advanced.{$key}"] = 'unknown advanced key';
            }
        }
        return [$clean, $errors];
    }

    /** @param array<string,mixed> $tree */
    private function set(array &$tree, string $path, mixed $value): void
    {
        $parts = explode('.', $path);
        $node = &$tree;
        foreach ($parts as $i => $part) {
            if ($i === count($parts) - 1) {
                $node[$part] = $value;
                return;
            }
            if (!isset($node[$part]) || !is_array($node[$part])) {
                $node[$part] = [];
            }
            $node = &$node[$part];
        }
    }
}

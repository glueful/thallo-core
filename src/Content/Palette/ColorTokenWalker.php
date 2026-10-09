<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Palette;

use Thallo\Contracts\Style\BlockStyleRegistry;
use Thallo\Contracts\Style\Palette;
use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Schema\ContentTypeSchema;

/**
 * Every colour-token location a document holds (custom palette spec §4.1, §4.5): style records
 * (targets, parts, hover, breakpoints) at any depth, colour token content fields, nested blocks,
 * and the page, region and layout style frames. Usage, normalisation and Replace all walk through
 * here, so they can never disagree about where a colour lives.
 *
 * A location inside a block is the innermost block's id plus the path relative to that block
 * (`head00000001:settings.style.colors.text`): stable when blocks move or another takes an index.
 * A block without an id (raw data) falls back to its index path. Locations outside blocks are
 * dotted paths (`_presentation.style.colors.surface`, `settings.style.colors.text`).
 */
final class ColorTokenWalker
{
    public const KIND_ENTRY = 'entry';
    public const KIND_REGION = 'region';
    public const KIND_LAYOUT = 'layout';
    public const KIND_SECTION = 'saved_section';
    public const KIND_CLASS = 'style_class';

    /** @var array<string, list<string>>|null block type => its colour token field names */
    private ?array $tokenFields = null;

    public function __construct(
        private readonly BlockStyleRegistry $registry,
        private readonly BlockTypeRepository $blockTypes,
    ) {
    }

    /**
     * Visits every colour-token value; `$fn(string $location, string $token): ?string` returns a
     * replacement token, or null to keep it. Returns the document with the replacements made.
     *
     * @param array<string,mixed> $doc
     * @return array<string,mixed>
     */
    public function map(string $kind, array $doc, callable $fn, ?ContentTypeSchema $schema = null): array
    {
        foreach ($this->roots($kind, $schema) as [$key, $type]) {
            $node = self::get($doc, $key);
            if ($node === null) {
                continue;
            }
            $node = $type === 'blocks' ? $this->blocks($node, $key, $fn) : self::style($node, $key, $fn);
            $doc = self::set($doc, $key, $node);
        }
        return $doc;
    }

    /**
     * @param array<string,mixed> $doc
     * @return array<string,string> location => token, colour tokens only
     */
    public function tokens(string $kind, array $doc, ?ContentTypeSchema $schema = null): array
    {
        $out = [];
        $this->map($kind, $doc, static function (string $loc, string $token) use (&$out): ?string {
            $out[$loc] = $token;
            return null;
        }, $schema);
        return $out;
    }

    /** @param array<string,mixed> $doc */
    public function hasBrand(string $kind, array $doc, ?ContentTypeSchema $schema = null): bool
    {
        foreach ($this->tokens($kind, $doc, $schema) as $token) {
            if (Palette::slotOf($token) !== null) {
                return true;
            }
        }
        return false;
    }

    /** @return list<array{0:string,1:string}> dotted root key and 'blocks'|'style' */
    private function roots(string $kind, ?ContentTypeSchema $schema): array
    {
        if ($kind === self::KIND_ENTRY) {
            $roots = [];
            foreach ($schema?->fields() ?? [] as $field) {
                if ($field->type === 'blocks') {
                    $roots[] = [$field->name, 'blocks'];
                }
            }
            $roots[] = ['_presentation.style', 'style'];
            return $roots;
        }
        return match ($kind) {
            self::KIND_REGION, self::KIND_LAYOUT => [['blocks', 'blocks'], ['settings.style', 'style']],
            self::KIND_SECTION => [['blocks', 'blocks']],
            self::KIND_CLASS => [['style', 'style']],
            default => throw new \InvalidArgumentException("unknown document kind {$kind}"),
        };
    }

    private function blocks(mixed $list, string $at, callable $fn): mixed
    {
        if (!is_array($list)) {
            return $list;
        }
        foreach ($list as $i => $block) {
            if (!is_array($block) || !is_string($block['type'] ?? null)) {
                continue;
            }
            // Identity, not position: the innermost block's id, then the path relative to it.
            $id = is_string($block['id'] ?? null) && $block['id'] !== '' ? $block['id'] : null;
            $base = $id !== null ? "{$id}:" : "{$at}.{$i}.";
            if (is_array($block['settings']['style'] ?? null)) {
                $block['settings']['style'] = self::style($block['settings']['style'], "{$base}settings.style", $fn);
            }
            $parts = is_array($block['settings']['parts'] ?? null) ? $block['settings']['parts'] : [];
            foreach ($parts as $name => $part) {
                if (is_array($part)) {
                    $block['settings']['parts'][$name] = self::style($part, "{$base}settings.parts.{$name}", $fn);
                }
            }
            foreach ($this->tokenFieldsOf($block['type']) as $field) {
                $value = $block['data'][$field] ?? null;
                if (
                    is_array($value) && ($value['type'] ?? null) === 'token' && is_string($value['value'] ?? null)
                    && str_starts_with($value['value'], 'color.')
                ) {
                    $new = $fn("{$base}data.{$field}", $value['value']);
                    if ($new !== null) {
                        $block['data'][$field]['value'] = $new;
                    }
                }
            }
            foreach ($this->registry->regionsFor($block['type']) as $slot) {
                if (isset($block['data'][$slot])) {
                    $block['data'][$slot] = $this->blocks($block['data'][$slot], "{$base}data.{$slot}", $fn);
                }
            }
            $list[$i] = $block;
        }
        return $list;
    }

    private static function style(mixed $node, string $at, callable $fn): mixed
    {
        if (!is_array($node)) {
            return $node;
        }
        if (($node['type'] ?? null) === 'token' && is_string($node['value'] ?? null)) {
            if (str_starts_with($node['value'], 'color.')) {
                $new = $fn($at, $node['value']);
                if ($new !== null) {
                    $node['value'] = $new;
                }
            }
            return $node;
        }
        foreach ($node as $k => $child) {
            if (is_array($child)) {
                $node[$k] = self::style($child, "{$at}.{$k}", $fn);
            }
        }
        return $node;
    }

    /** @return list<string> */
    private function tokenFieldsOf(string $type): array
    {
        if ($this->tokenFields === null) {
            $this->tokenFields = [];
            foreach ($this->blockTypes->schemasBySlug() as $slug => $schema) {
                foreach ($schema->fields() as $field) {
                    if ($field->type === 'token' && $field->domain === 'color') {
                        $this->tokenFields[$slug][] = $field->name;
                    }
                }
            }
        }
        return $this->tokenFields[$type] ?? [];
    }

    /** @param array<string,mixed> $doc */
    private static function get(array $doc, string $dotted): mixed
    {
        $node = $doc;
        foreach (explode('.', $dotted) as $k) {
            if (!is_array($node) || !array_key_exists($k, $node)) {
                return null;
            }
            $node = $node[$k];
        }
        return $node;
    }

    /**
     * Only after get() found the node, so it never creates keys.
     *
     * @param array<string,mixed> $doc
     * @return array<string,mixed>
     */
    private static function set(array $doc, string $dotted, mixed $value): array
    {
        $ref = &$doc;
        foreach (explode('.', $dotted) as $k) {
            $ref = &$ref[$k];
        }
        $ref = $value;
        return $doc;
    }
}

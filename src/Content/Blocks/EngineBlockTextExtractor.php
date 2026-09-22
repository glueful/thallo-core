<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Blocks;

use Thallo\Contracts\Search\BlockTextExtractor;
use Thallo\Core\Content\Schema\ContentTypeSchema;

/**
 * BlockTextExtractor over the block type registry. A block's fields are read by their schema, so
 * an enum, a number or a setting never becomes a "word"; an unknown block type or a malformed item
 * contributes nothing. Nesting is bounded by BlockDepth::MAX, as it is everywhere else.
 */
final class EngineBlockTextExtractor implements BlockTextExtractor
{
    public function __construct(private readonly BlockTypeRepository $blockTypes)
    {
    }

    public function textOf(mixed $blocks): array
    {
        $out = [];
        $this->walk($blocks, $this->blockTypes->schemasBySlug(), 1, $out);

        return $out;
    }

    /**
     * @param array<string, ContentTypeSchema> $schemas
     * @param list<string> $out
     */
    private function walk(mixed $blocks, array $schemas, int $depth, array &$out): void
    {
        if (!is_array($blocks) || !array_is_list($blocks) || $depth > BlockDepth::MAX) {
            return;
        }
        foreach ($blocks as $item) {
            if (!is_array($item) || !is_string($item['type'] ?? null) || !is_array($item['data'] ?? null)) {
                continue;
            }
            $schema = $schemas[$item['type']] ?? null;
            if ($schema === null) {
                continue;
            }
            foreach ($schema->fields() as $field) {
                $value = $item['data'][$field->name] ?? null;
                if ($field->type === 'blocks') {
                    $this->walk($value, $schemas, $depth + 1, $out);
                } elseif (in_array($field->type, ['string', 'text'], true) && is_string($value)) {
                    $text = $field->format === 'rich' ? self::htmlToText($value) : trim($value);
                    if (self::isWords($text)) {
                        $out[] = $text;
                    }
                }
            }
        }
    }

    private static function htmlToText(string $html): string
    {
        $text = (string) preg_replace('~<(?:/(?:p|div|h[1-6]|li|tr|blockquote|pre)|br\s*/?)>~i', ' ', $html);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** Words a reader sees: not empty, not a bare link or path, and at least one letter or digit. */
    private static function isWords(string $text): bool
    {
        if ($text === '' || preg_match('/[\p{L}\p{N}]/u', $text) !== 1) {
            return false;
        }
        $oneToken = preg_match('/\s/u', $text) !== 1;

        return !($oneToken && (preg_match('~\A[a-z][a-z0-9+.-]*:~i', $text) === 1 || str_contains($text, '/')));
    }
}

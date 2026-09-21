<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Patterns;

use Thallo\Core\Content\Blocks\BlockFactory;

/**
 * The section and page library as THIS site can use it.
 *
 * {@see StarterPatterns} says what the patterns are; this resolves them against the site's block
 * types. Every block in a pattern is laid over the block factory's canonical instance of its type
 * — the same starting point as a block added from the palette — so a pattern never has to repeat
 * a type's defaults. A pattern that uses a block type the site has switched off (or never had) is
 * not offered, and neither is a page made of such a section: the library only hands out
 * documents a save accepts.
 *
 * Blocks carry no ids: the editor mints them, as it does for every block it creates.
 */
final class PatternLibrary
{
    /** @var array<string,array<string,mixed>|false> the factory's canonical data per type; false when unusable */
    private array $made = [];

    public function __construct(private readonly BlockFactory $factory)
    {
    }

    /**
     * Sections first, in their categories' order, then pages.
     *
     * @return list<array{slug:string,kind:string,label:string,category:string,description:string,blocks:list<array<string,mixed>>}>
     */
    public function all(): array
    {
        $this->made = []; // the site's block types can change between two askings
        $sections = [];
        foreach (StarterPatterns::sections() as $section) {
            $block = $this->resolve($section['block']);
            if ($block !== null) {
                $sections[$section['slug']] = [
                    'slug' => $section['slug'],
                    'kind' => 'section',
                    'label' => $section['label'],
                    'category' => $section['category'],
                    'description' => $section['description'],
                    'blocks' => [$block],
                ];
            }
        }
        $pages = [];
        foreach (StarterPatterns::pages() as $page) {
            $blocks = [];
            foreach ($page['sections'] as $slug) {
                if (!isset($sections[$slug])) {
                    continue 2; // a page is offered whole or not at all
                }
                $blocks[] = $sections[$slug]['blocks'][0];
            }
            $pages[] = [
                'slug' => $page['slug'],
                'kind' => 'page',
                'label' => $page['label'],
                'category' => 'Pages',
                'description' => $page['description'],
                'blocks' => $blocks,
            ];
        }
        return [...array_values($sections), ...$pages];
    }

    /**
     * The pattern's block over its type's canonical instance, all the way down; null when any
     * block in it is of a type this site cannot use.
     *
     * @param array<string,mixed> $block
     * @return array<string,mixed>|null
     */
    private function resolve(array $block): ?array
    {
        $type = (string) $block['type'];
        $this->made[$type] ??= $this->make($type);
        $made = $this->made[$type];
        if ($made === false) {
            return null;
        }
        $data = array_replace($made, (array) ($block['data'] ?? []));
        foreach ($data as $key => $value) {
            if (is_array($value) && $value !== [] && is_array($value[0] ?? null) && isset($value[0]['type'])) {
                $children = [];
                foreach ($value as $child) {
                    $resolved = $this->resolve($child);
                    if ($resolved === null) {
                        return null;
                    }
                    $children[] = $resolved;
                }
                $data[$key] = $children;
            }
        }
        return ['type' => $type, 'data' => $data, 'settings' => (array) ($block['settings'] ?? [])];
    }

    /** @return array<string,mixed>|false the canonical data, or false for an unusable type */
    private function make(string $type): array|false
    {
        $made = $this->factory->make($type);
        return $made === null || !$made['active'] ? false : (array) $made['block']['data'];
    }
}

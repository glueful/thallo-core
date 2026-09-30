<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Patterns;

use Thallo\Contracts\Patterns\PatternContributorRegistry;
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
 * Packs add page sections and templates through the {@see PatternContributorRegistry}: theirs are
 * resolved by the same rules, listed after core's, and a template naming a section that is not
 * offered is not offered either. A pattern's `requires` names what an editor chooses after
 * inserting it — 'product' when a section's product block needs one, and so for any template made
 * of such a section.
 *
 * Blocks carry no ids: the editor mints them, as it does for every block it creates.
 */
final class PatternLibrary
{
    /** @var array<string,array<string,mixed>|false> the factory's canonical data per type; false when unusable */
    private array $made = [];

    public function __construct(
        private readonly BlockFactory $factory,
        /** The site's own sections, saved from the stage: listed after the shipped ones. */
        private readonly ?SavedSectionRepository $saved = null,
        /** Packs' page sections and templates: listed after core's. */
        private readonly ?PatternContributorRegistry $contributors = null,
    ) {
    }

    /**
     * Sections first — a page body's, then the header's and footer's — then pages and the regions'
     * templates, then the site's saved sections. Every entry says where it belongs: `scope` `page`,
     * or `region` with its `region`.
     *
     * @return list<array{slug:string,kind:string,label:string,category:string,description:string,requires:?string,blocks:list<array<string,mixed>>,scope:string,region:?string,saved:bool,id:?string}>
     */
    public function all(): array
    {
        $this->made = []; // the site's block types can change between two askings
        $contributors = $this->contributors?->all() ?? [];
        $sectionSources = [...StarterPatterns::sections()];
        foreach ($contributors as $contributor) {
            foreach ($contributor->sections() as $section) {
                $sectionSources[] = [
                    'slug' => $section->slug,
                    'label' => $section->label,
                    'category' => $section->category,
                    'description' => $section->description,
                    'block' => $section->block,
                    'requires' => $section->requires,
                ];
            }
        }
        $sections = [];
        foreach ([...$sectionSources, ...StarterPatterns::regionSections()] as $section) {
            $block = $this->resolve($section['block']);
            if ($block !== null) {
                $sections[$section['slug']] = [
                    'slug' => $section['slug'],
                    'kind' => 'section',
                    'label' => $section['label'],
                    'category' => $section['category'],
                    'description' => $section['description'],
                    'requires' => $section['requires'] ?? null,
                    'blocks' => [$block],
                    ...self::place($section['region'] ?? null),
                ];
            }
        }
        $pageSources = [...StarterPatterns::pages()];
        foreach ($contributors as $contributor) {
            foreach ($contributor->templates() as $template) {
                $pageSources[] = [
                    'slug' => $template->slug,
                    'label' => $template->label,
                    'category' => $template->category,
                    'description' => $template->description,
                    'sections' => $template->sections,
                ];
            }
        }
        $pages = [];
        foreach ([...$pageSources, ...StarterPatterns::regionTemplates()] as $page) {
            $blocks = [];
            $requires = null;
            foreach ($page['sections'] as $slug) {
                if (!isset($sections[$slug])) {
                    continue 2; // a page is offered whole or not at all
                }
                $blocks[] = $sections[$slug]['blocks'][0];
                $requires ??= $sections[$slug]['requires'];
            }
            $pages[] = [
                'slug' => $page['slug'],
                'kind' => 'page',
                'label' => $page['label'],
                'category' => $page['category'] ?? 'Pages',
                'description' => $page['description'],
                'requires' => $requires,
                'blocks' => $blocks,
                ...self::place($page['region'] ?? null),
            ];
        }
        return [...array_values($sections), ...$pages, ...$this->savedSections()];
    }

    /** @return array{scope: string, region: ?string, saved: false, id: null} a shipped pattern's place */
    private static function place(?string $region): array
    {
        return ['scope' => $region === null ? 'page' : 'region', 'region' => $region, 'saved' => false, 'id' => null];
    }

    /**
     * The site's saved sections, as sections: `saved` true, `id` for renaming and deleting them.
     * One whose block type can no longer be used is left out, as a shipped section would be.
     *
     * @return list<array<string,mixed>>
     */
    private function savedSections(): array
    {
        $out = [];
        foreach ($this->saved?->all() ?? [] as $row) {
            $block = $this->resolve($row['block']);
            if ($block === null) {
                continue;
            }
            $out[] = [
                'slug' => 'saved-' . $row['id'],
                'kind' => 'section',
                'label' => $row['name'],
                'category' => $row['category'],
                'description' => $row['description'] ?? '',
                'requires' => null,
                'blocks' => [$block],
                'scope' => $row['scope'],
                'region' => $row['region'],
                'saved' => true,
                'id' => $row['id'],
            ];
        }
        return $out;
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

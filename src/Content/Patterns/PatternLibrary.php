<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Patterns;

use Thallo\Contracts\Layouts\LayoutSurfaceRegistry;
use Thallo\Contracts\Patterns\LayoutSection;
use Thallo\Contracts\Patterns\LayoutTemplate;
use Thallo\Contracts\Patterns\PatternContributorRegistry;
use Thallo\Core\Content\Blocks\BlockFactory;
use Thallo\Core\Content\Layouts\LayoutSaver;
use Thallo\Core\Content\Layouts\LayoutValidator;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Validation\ValidationException;

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
 * A layout's sections and templates are served per target ({@see self::forLayout()}): built for the
 * target's type, validated against it — a template through the layout validator itself — and
 * returned exactly as validated. The page library never holds them.
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
        /** The layout surfaces this site has; null offers no layout patterns. */
        private readonly ?LayoutSurfaceRegistry $surfaces = null,
        /** What a layout accepts: a template is offered only when it passes. */
        private readonly ?LayoutValidator $layouts = null,
        /** The target's type, whose schema a layout pattern is built from. */
        private readonly ?ContentTypeRepository $types = null,
    ) {
    }

    /**
     * Sections first — a page body's, then the header's and footer's — then pages and the regions'
     * templates, then the site's saved sections. Every entry says where it belongs: `scope` `page`,
     * or `region` with its `region`.
     *
     * @return list<array{slug:string,kind:string,label:string,category:string,description:string,requires:?string,blocks:list<array<string,mixed>>,scope:string,region:?string,surface:?string,settings:?array<string,string>,saved:bool,id:?string}>
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

    /**
     * A layout's sections, then its templates, then the site's sections saved for the surface —
     * each shipped one built for the target, validated against it and served as validated
     * (normalised, id-less). Nothing for a target that cannot have a layout.
     *
     * @return list<array<string,mixed>>
     * @throws \InvalidArgumentException for a surface this site has no layout for
     */
    public function forLayout(string $surface, string $target): array
    {
        if ($this->surfaces?->get($surface) === null || $this->layouts === null) {
            throw new \InvalidArgumentException("unknown layout surface '{$surface}'");
        }
        if ($this->layouts->targetError($surface, $target) !== null) {
            return [];
        }
        $this->made = [];
        $type = LayoutSaver::typeOf($surface, $target);
        $row = $type === null ? null : $this->types?->findBySlug($type);
        $built = LayoutPatterns::targetFor($surface, $target, $row === null ? null : (array) ($row['schema'] ?? []));

        [$sections, $templates] = $this->layoutSources($surface);
        $out = [];
        foreach ($sections as $section) {
            $block = ($section->build)($built);
            $resolved = $block === null ? null : $this->resolve($block);
            if ($resolved === null) {
                continue;
            }
            $checked = $this->layouts->fragment($surface, $target, [$resolved]);
            if ($checked['errors'] !== []) {
                continue;
            }
            $out[] = [
                'slug' => $section->slug, 'kind' => 'section', 'label' => $section->label,
                'category' => $section->category, 'description' => $section->description, 'requires' => null,
                'blocks' => $checked['blocks'], 'scope' => 'layout', 'region' => null, 'surface' => $surface,
                'settings' => null, 'field_labels' => null, 'saved' => false, 'id' => null,
            ];
        }
        foreach ($templates as $template) {
            $tree = ($template->build)($built);
            $resolved = $tree === null ? null : $this->resolveAll($tree);
            if ($resolved === null) {
                continue;
            }
            try {
                $blocks = self::withIds($resolved);
                $clean = $this->layouts->validate($surface, $target, $blocks, $template->settings, [], false);
            } catch (ValidationException) {
                continue;
            }
            $out[] = [
                'slug' => $template->slug, 'kind' => 'page', 'label' => $template->label, 'category' => 'Layouts',
                'description' => $template->description, 'requires' => null,
                'blocks' => self::withoutIds($clean['blocks']), 'scope' => 'layout', 'region' => null,
                'surface' => $surface, 'settings' => $clean['settings'], 'field_labels' => null, 'saved' => false,
                'id' => null,
            ];
        }
        foreach ($this->saved?->all() ?? [] as $saved) {
            if (($saved['scope'] ?? null) === 'layout' && ($saved['surface'] ?? null) === $surface) {
                $entry = $this->savedEntry($saved);
                if ($entry !== null) {
                    $out[] = $entry;
                }
            }
        }
        return $out;
    }

    /** @return list<string> every shipped layout pattern's slug, for the surfaces this site has */
    public function layoutSlugs(): array
    {
        $out = [];
        foreach (LayoutPatterns::SURFACES as $surface) {
            if ($this->surfaces?->get($surface) === null) {
                continue;
            }
            [$sections, $templates] = $this->layoutSources($surface);
            foreach ([...$sections, ...$templates] as $pattern) {
                $out[] = $pattern->slug;
            }
        }
        return $out;
    }

    /** @return array{0: list<LayoutSection>, 1: list<LayoutTemplate>} core's, then the packs', for a surface */
    private function layoutSources(string $surface): array
    {
        $sections = LayoutPatterns::sections();
        $templates = LayoutPatterns::templates();
        foreach ($this->contributors?->layoutContributors() ?? [] as $contributor) {
            array_push($sections, ...$contributor->layoutSections());
            array_push($templates, ...$contributor->layoutTemplates());
        }
        $mine = static fn (LayoutSection|LayoutTemplate $p): bool => $p->surface === $surface;
        return [array_values(array_filter($sections, $mine)), array_values(array_filter($templates, $mine))];
    }

    /**
     * @param list<array<string,mixed>> $blocks
     * @return list<array<string,mixed>>|null each over its type's canonical instance; null when one cannot be
     */
    private function resolveAll(array $blocks): ?array
    {
        $out = [];
        foreach ($blocks as $block) {
            $resolved = $this->resolve($block);
            if ($resolved === null) {
                return null;
            }
            $out[] = $resolved;
        }
        return $out;
    }

    /**
     * Every block, nested ones too, with a temporary id — what a layout's validation wants.
     *
     * @param list<array<string,mixed>> $blocks
     * @return list<array<string,mixed>>
     */
    public static function withIds(array $blocks, int &$n = 0): array
    {
        foreach ($blocks as $i => $block) {
            $blocks[$i] = ['id' => 'tmplib' . str_pad((string) ++$n, 6, '0', STR_PAD_LEFT)] + $block;
            foreach ($block['data'] ?? [] as $key => $value) {
                if (is_array($value) && array_is_list($value) && isset($value[0]['type'])) {
                    $blocks[$i]['data'][$key] = self::withIds($value, $n);
                }
            }
        }
        return $blocks;
    }

    /**
     * @param list<array<string,mixed>> $blocks
     * @return list<array<string,mixed>> the blocks with every id removed, nested ones too
     */
    public static function withoutIds(array $blocks): array
    {
        foreach ($blocks as $i => $block) {
            unset($blocks[$i]['id']);
            foreach ($block['data'] ?? [] as $key => $value) {
                if (is_array($value) && array_is_list($value) && isset($value[0]['type'])) {
                    $blocks[$i]['data'][$key] = self::withoutIds($value);
                }
            }
        }
        return $blocks;
    }

    /** @return array{scope: string, region: ?string, surface: null, settings: null, field_labels: null, saved: false, id: null} */
    private static function place(?string $region): array
    {
        return [
            'scope' => $region === null ? 'page' : 'region', 'region' => $region, 'surface' => null,
            'settings' => null, 'field_labels' => null, 'saved' => false, 'id' => null,
        ];
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
            if (($row['scope'] ?? null) === 'layout') {
                continue; // a layout's own: offered in its surface's layouts only
            }
            $entry = $this->savedEntry($row);
            if ($entry !== null) {
                $out[] = $entry;
            }
        }
        return $out;
    }

    /**
     * A stored saved section as its library entry: `saved` true, `id` for renaming and deleting it;
     * null when a block type in it can no longer be used, as a shipped section would be left out.
     *
     * @param array<string,mixed> $row a {@see SavedSectionRepository} row
     * @return array<string,mixed>|null
     */
    public function savedEntry(array $row): ?array
    {
        $block = $this->resolve((array) $row['block']);
        if ($block === null) {
            return null;
        }
        return [
            'slug' => 'saved-' . $row['id'],
            'kind' => 'section',
            'label' => $row['name'],
            'category' => $row['category'],
            'description' => $row['description'] ?? '',
            'requires' => null,
            'blocks' => [$block],
            'scope' => $row['scope'],
            'region' => $row['region'] ?? null,
            'surface' => $row['surface'] ?? null,
            'settings' => null,
            'field_labels' => $row['field_labels'] ?? null,
            'saved' => true,
            'id' => $row['id'],
        ];
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

<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Fonts;

use Glueful\Database\Connection;
use Thallo\Contracts\Style\BlockStyleRegistry;
use Thallo\Core\Content\Blocks\Sources\BlockDocumentSource;
use Thallo\Core\Content\Blocks\Sources\BlockDocumentSources;
use Thallo\Core\Content\Blocks\Sources\DocumentRef;
use Thallo\Core\Content\Blocks\Sources\EntryDraftsSource;
use Thallo\Core\Content\Blocks\Sources\EntryVersionsSource;
use Thallo\Core\Content\Blocks\Sources\LayoutsSource;
use Thallo\Core\Content\Blocks\Sources\PublishedEntriesSource;
use Thallo\Core\Content\Blocks\Sources\RegionsSource;
use Thallo\Core\Content\Blocks\Sources\SavedSectionsSource;
use Thallo\Core\Settings\GeneralSettings;

/**
 * Where a typeface is used (block typeface spec §2.6) — what the delete dialog lists. Every
 * block-bearing document source (drafts, publications, retained versions, the header and footer,
 * layouts, saved sections) at each block's `settings.style.typography.family` and each part's
 * `settings.parts.<name>.typography.family`, nested blocks included; plus style classes and the
 * Appearance Text and Headings. Workspace-scoped through the sources' own scoping. A retained version
 * that is also the current publication is counted as published.
 */
final class FontUsage
{
    public function __construct(
        private readonly BlockDocumentSources $sources,
        private readonly BlockStyleRegistry $registry,
        private readonly Connection $db,
        private readonly GeneralSettings $settings,
    ) {
    }

    /**
     * @return array{
     *   entries: list<array{uuid: string, title: string, locale: string, draft: bool, published: bool,
     *     versions: bool}>,
     *   regions: list<string>,
     *   layouts: list<array{id: string, name: string}>,
     *   saved_sections: list<array{id: string, name: string}>,
     *   style_classes: list<array{id: string, name: string}>,
     *   appearance: array{text: bool, headings: bool}
     * }
     */
    public function of(string $id): array
    {
        return $this->scan([$id])[$id];
    }

    /**
     * How many places name each family — as many as of() would list — from one scan of every source
     * (the Typefaces card's counts).
     *
     * @param list<string> $ids
     * @return array<string, int>
     */
    public function counts(array $ids): array
    {
        $out = [];
        foreach ($this->scan($ids) as $id => $usage) {
            $out[$id] = count($usage['entries']) + count($usage['regions']) + count($usage['layouts'])
                + count($usage['saved_sections']) + count($usage['style_classes'])
                + (int) $usage['appearance']['text'] + (int) $usage['appearance']['headings'];
        }
        return $out;
    }

    /**
     * @param list<string> $ids
     * @return array<string, array{
     *   entries: list<array{uuid: string, title: string, locale: string, draft: bool, published: bool,
     *     versions: bool}>,
     *   regions: list<string>,
     *   layouts: list<array{id: string, name: string}>,
     *   saved_sections: list<array{id: string, name: string}>,
     *   style_classes: list<array{id: string, name: string}>,
     *   appearance: array{text: bool, headings: bool}
     * }>
     */
    private function scan(array $ids): array
    {
        $wanted = array_fill_keys($ids, true);
        $found = array_fill_keys($ids, ['entries' => [], 'regions' => [], 'layouts' => [], 'sections' => []]);
        $published = $this->publishedVersionUuids();
        $visit = function (DocumentRef $ref) use ($wanted, $published, &$found): void {
            $used = [];
            foreach ($ref->schema->fields() as $field) {
                if ($field->type === 'blocks') {
                    $this->collect($ref->fields[$field->name] ?? null, $used);
                }
            }
            $used = array_intersect_key($used, $wanted);
            if ($used === []) {
                return;
            }
            if ($ref->sourceType === EntryVersionsSource::ID && isset($published[$ref->sourceId])) {
                return; // the current publication: counted as published
            }
            foreach (array_keys($used) as $id) {
                switch ($ref->sourceType) {
                    case EntryDraftsSource::ID:
                    case PublishedEntriesSource::ID:
                    case EntryVersionsSource::ID:
                        $uuid = $ref->sourceType === EntryVersionsSource::ID
                            ? (string) ($ref->meta['entry_uuid'] ?? $ref->sourceId)
                            : $ref->sourceId;
                        $key = $uuid . ':' . $ref->locale;
                        $found[$id]['entries'][$key] ??= ['uuid' => $uuid, 'title' => '',
                            'locale' => (string) $ref->locale, 'draft' => false, 'published' => false,
                            'versions' => false];
                        if ($found[$id]['entries'][$key]['title'] === '' && is_string($ref->fields['title'] ?? null)) {
                            $found[$id]['entries'][$key]['title'] = $ref->fields['title'];
                        }
                        $flag = match ($ref->sourceType) {
                            EntryDraftsSource::ID => 'draft',
                            PublishedEntriesSource::ID => 'published',
                            default => 'versions',
                        };
                        $found[$id]['entries'][$key][$flag] = true;
                        break;
                    case RegionsSource::ID:
                        $found[$id]['regions'][$ref->sourceId] = $ref->sourceId;
                        break;
                    case LayoutsSource::ID:
                        $found[$id]['layouts'][$ref->sourceId] = [
                            'id' => $ref->sourceId,
                            'name' => (string) ($ref->meta['target'] ?? ''),
                        ];
                        break;
                    case SavedSectionsSource::ID:
                        $found[$id]['sections'][$ref->sourceId] = [
                            'id' => $ref->sourceId,
                            'name' => (string) ($ref->meta['name'] ?? ''),
                        ];
                        break;
                }
            }
        };
        $this->sources->each(static fn (BlockDocumentSource $source, DocumentRef $ref) => $visit($ref));

        $classes = $this->styleClasses();
        $text = $this->settings->themeFontTextFamily();
        $headings = $this->settings->themeFontHeadingsFamily();
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = [
                'entries' => array_values($found[$id]['entries']),
                'regions' => array_values($found[$id]['regions']),
                'layouts' => array_values($found[$id]['layouts']),
                'saved_sections' => array_values($found[$id]['sections']),
                'style_classes' => $classes[$id] ?? [],
                'appearance' => ['text' => $text === $id, 'headings' => $headings === $id],
            ];
        }
        return $out;
    }

    /**
     * Every typeface ID a block list sets — targets and parts, nested blocks included — as keys of
     * `$found`.
     *
     * @param array<string, true> $found
     */
    private function collect(mixed $list, array &$found): void
    {
        if (!is_array($list)) {
            return;
        }
        foreach ($list as $block) {
            if (!is_array($block) || !is_string($block['type'] ?? null)) {
                continue;
            }
            $settings = is_array($block['settings'] ?? null) ? $block['settings'] : [];
            $own = self::familyOf($settings['style'] ?? null);
            if ($own !== null) {
                $found[$own] = true;
            }
            foreach (is_array($settings['parts'] ?? null) ? $settings['parts'] : [] as $part) {
                $partFamily = self::familyOf($part);
                if ($partFamily !== null) {
                    $found[$partFamily] = true;
                }
            }
            foreach ($this->registry->regionsFor($block['type']) as $slot) {
                $this->collect($block['data'][$slot] ?? null, $found);
            }
        }
    }

    /** The typeface ID a style record sets, if any. */
    private static function familyOf(mixed $style): ?string
    {
        $value = is_array($style) ? ($style['typography']['family'] ?? null) : null;
        return is_array($value) && ($value['type'] ?? null) === 'font' && is_string($value['value'] ?? null)
            ? $value['value']
            : null;
    }

    /** @return array<string, list<array{id: string, name: string}>> the style classes naming each family */
    private function styleClasses(): array
    {
        $found = [];
        foreach ($this->db->table('style_classes')->select(['id', 'name', 'style'])->get() as $row) {
            $style = is_string($row['style'] ?? null) ? json_decode($row['style'], true) : $row['style'];
            $family = self::familyOf($style);
            if ($family !== null) {
                $found[$family][] = ['id' => (string) $row['id'], 'name' => (string) $row['name']];
            }
        }
        return $found;
    }

    /** @return array<string, true> version uuids that are a current publication */
    private function publishedVersionUuids(): array
    {
        $out = [];
        foreach ($this->db->table('entry_publications')->select(['version_uuid'])->get() as $row) {
            $out[(string) $row['version_uuid']] = true;
        }
        return $out;
    }
}

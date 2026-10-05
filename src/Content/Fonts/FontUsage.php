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
        $entries = [];
        $regions = [];
        $layouts = [];
        $sections = [];
        $published = $this->publishedVersionUuids();
        $visit = function (DocumentRef $ref) use ($id, $published, &$entries, &$regions, &$layouts, &$sections): void {
            if (!$this->uses($ref, $id)) {
                return;
            }
            switch ($ref->sourceType) {
                case EntryDraftsSource::ID:
                case PublishedEntriesSource::ID:
                case EntryVersionsSource::ID:
                    if ($ref->sourceType === EntryVersionsSource::ID && isset($published[$ref->sourceId])) {
                        return; // the current publication: counted as published
                    }
                    $uuid = $ref->sourceType === EntryVersionsSource::ID
                        ? (string) ($ref->meta['entry_uuid'] ?? $ref->sourceId)
                        : $ref->sourceId;
                    $key = $uuid . ':' . $ref->locale;
                    $entries[$key] ??= ['uuid' => $uuid, 'title' => '', 'locale' => (string) $ref->locale,
                        'draft' => false, 'published' => false, 'versions' => false];
                    if ($entries[$key]['title'] === '' && is_string($ref->fields['title'] ?? null)) {
                        $entries[$key]['title'] = $ref->fields['title'];
                    }
                    $flag = match ($ref->sourceType) {
                        EntryDraftsSource::ID => 'draft',
                        PublishedEntriesSource::ID => 'published',
                        default => 'versions',
                    };
                    $entries[$key][$flag] = true;
                    break;
                case RegionsSource::ID:
                    $regions[$ref->sourceId] = $ref->sourceId;
                    break;
                case LayoutsSource::ID:
                    $target = (string) ($ref->meta['target'] ?? '');
                    $layouts[$ref->sourceId] = ['id' => $ref->sourceId, 'name' => $target];
                    break;
                case SavedSectionsSource::ID:
                    $sections[$ref->sourceId] = ['id' => $ref->sourceId, 'name' => (string) ($ref->meta['name'] ?? '')];
                    break;
            }
        };
        $this->sources->each(static fn (BlockDocumentSource $source, DocumentRef $ref) => $visit($ref));

        return [
            'entries' => array_values($entries),
            'regions' => array_values($regions),
            'layouts' => array_values($layouts),
            'saved_sections' => array_values($sections),
            'style_classes' => $this->styleClasses($id),
            'appearance' => [
                'text' => $this->settings->themeFontTextFamily() === $id,
                'headings' => $this->settings->themeFontHeadingsFamily() === $id,
            ],
        ];
    }

    private function uses(DocumentRef $ref, string $id): bool
    {
        foreach ($ref->schema->fields() as $field) {
            if ($field->type === 'blocks' && $this->walk($ref->fields[$field->name] ?? null, $id)) {
                return true;
            }
        }
        return false;
    }

    private function walk(mixed $list, string $id): bool
    {
        if (!is_array($list)) {
            return false;
        }
        foreach ($list as $block) {
            if (!is_array($block) || !is_string($block['type'] ?? null)) {
                continue;
            }
            $settings = is_array($block['settings'] ?? null) ? $block['settings'] : [];
            if (self::familyOf($settings['style'] ?? null) === $id) {
                return true;
            }
            foreach (is_array($settings['parts'] ?? null) ? $settings['parts'] : [] as $part) {
                if (self::familyOf($part) === $id) {
                    return true;
                }
            }
            foreach ($this->registry->regionsFor($block['type']) as $slot) {
                if ($this->walk($block['data'][$slot] ?? null, $id)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** The typeface ID a style record sets, if any. */
    private static function familyOf(mixed $style): ?string
    {
        $value = is_array($style) ? ($style['typography']['family'] ?? null) : null;
        return is_array($value) && ($value['type'] ?? null) === 'font' && is_string($value['value'] ?? null)
            ? $value['value']
            : null;
    }

    /** @return list<array{id: string, name: string}> */
    private function styleClasses(string $id): array
    {
        $found = [];
        foreach ($this->db->table('style_classes')->select(['id', 'name', 'style'])->get() as $row) {
            $style = is_string($row['style'] ?? null) ? json_decode($row['style'], true) : $row['style'];
            if (self::familyOf($style) === $id) {
                $found[] = ['id' => (string) $row['id'], 'name' => (string) $row['name']];
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

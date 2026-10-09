<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Palette;

use Glueful\Database\Connection;
use Thallo\Contracts\Style\Palette;
use Thallo\Core\Content\Blocks\Sources\BlockDocumentSources;
use Thallo\Core\Content\Blocks\Sources\DocumentRef;
use Thallo\Core\Content\Blocks\Sources\EntryDraftsSource;
use Thallo\Core\Content\Blocks\Sources\EntryVersionsSource;
use Thallo\Core\Content\Blocks\Sources\LayoutsSource;
use Thallo\Core\Content\Blocks\Sources\PublishedEntriesSource;
use Thallo\Core\Content\Blocks\Sources\RegionsSource;
use Thallo\Core\Content\Blocks\Sources\SavedSectionsSource;
use Thallo\Core\Content\Schema\ContentTypeSchema;

/**
 * Where a brand colour is used (custom palette spec §4.1), modelled on FontUsage. **Blocking**:
 * what the site renders now — entry drafts, each entry's current publication, regions (their blocks
 * and their own style), layouts (their blocks and their frame), saved sections and style classes;
 * while any remain the slot cannot be cleared. **Historical**: retained versions that are neither a
 * draft nor the current publication — reported, never blocking, never rewritten. Every colour
 * location is found by ColorTokenWalker; the slot's contrast token counts as the slot.
 */
final class BrandColorUsage
{
    public function __construct(
        private readonly BlockDocumentSources $sources,
        private readonly ColorTokenWalker $walker,
        private readonly Connection $db,
    ) {
    }

    /**
     * @return array{
     *   slot: int,
     *   blocking: array{
     *     entries: list<array{uuid: string, title: string, locale: string, draft: bool, published: bool}>,
     *     regions: list<string>, layouts: list<array{id: string, name: string}>,
     *     saved_sections: list<array{id: string, name: string}>, style_classes: list<array{id: string, name: string}>,
     *     total: int, contrast_references: bool
     *   },
     *   historical: array{entries: list<array{uuid: string, title: string, locale: string, versions: int}>, total: int}
     * }
     */
    public function of(int $slot): array
    {
        $entries = [];
        $history = [];
        $regions = [];
        $layouts = [];
        $sections = [];
        $contrast = false;
        $published = $this->publishedVersionUuids();
        $this->sources->each(function (
            mixed $source,
            DocumentRef $ref
        ) use (
            $slot,
            $published,
            &$entries,
            &$history,
            &$regions,
            &$layouts,
            &$sections,
            &$contrast,
        ): void {
            $kind = $ref->sourceType === EntryDraftsSource::ID || $ref->sourceType === PublishedEntriesSource::ID
                || $ref->sourceType === EntryVersionsSource::ID
                ? ColorTokenWalker::KIND_ENTRY
                : ColorTokenWalker::KIND_SECTION; // regions, layouts and saved sections hold {blocks}
            $uses = $this->uses($kind, $ref->fields, $slot, $ref->schema);
            if ($uses === null) {
                return;
            }
            $title = is_string($ref->fields['title'] ?? null) ? $ref->fields['title'] : '';
            switch ($ref->sourceType) {
                case EntryVersionsSource::ID:
                    if (isset($published[$ref->sourceId])) {
                        return; // the current publication: counted through PublishedEntriesSource
                    }
                    $uuid = (string) ($ref->meta['entry_uuid'] ?? $ref->sourceId);
                    $key = $uuid . ':' . $ref->locale;
                    $history[$key] ??= ['uuid' => $uuid, 'title' => $title, 'locale' => (string) $ref->locale,
                        'versions' => 0];
                    $history[$key]['versions']++;
                    return;
                case EntryDraftsSource::ID:
                case PublishedEntriesSource::ID:
                    $key = $ref->sourceId . ':' . $ref->locale;
                    $entries[$key] ??= ['uuid' => $ref->sourceId, 'title' => '', 'locale' => (string) $ref->locale,
                        'draft' => false, 'published' => false];
                    if ($entries[$key]['title'] === '') {
                        $entries[$key]['title'] = $title;
                    }
                    $entries[$key][$ref->sourceType === EntryDraftsSource::ID ? 'draft' : 'published'] = true;
                    break;
                case RegionsSource::ID:
                    $regions[$ref->sourceId] = $ref->sourceId;
                    break;
                case LayoutsSource::ID:
                    $name = (string) ($ref->meta['target'] ?? '');
                    $layouts[$ref->sourceId] = ['id' => $ref->sourceId, 'name' => $name];
                    break;
                case SavedSectionsSource::ID:
                    $sections[$ref->sourceId] = ['id' => $ref->sourceId, 'name' => (string) ($ref->meta['name'] ?? '')];
                    break;
                default:
                    return;
            }
            $contrast = $contrast || $uses;
        });
        foreach ($this->db->table('regions')->select(['slug', 'settings'])->get() as $row) {
            $frame = ['settings' => self::json($row['settings'] ?? null)];
            $uses = $this->uses(ColorTokenWalker::KIND_REGION, $frame, $slot);
            if ($uses !== null) {
                $regions[(string) $row['slug']] = (string) $row['slug'];
                $contrast = $contrast || $uses;
            }
        }
        $live = $this->db->table('layouts')->select(['surface', 'target', 'settings'])->whereNotNull('blocks')->get();
        foreach ($live as $row) {
            $frame = ['settings' => self::json($row['settings'] ?? null)];
            $uses = $this->uses(ColorTokenWalker::KIND_LAYOUT, $frame, $slot);
            if ($uses !== null) {
                $id = $row['surface'] . ':' . $row['target'];
                $layouts[$id] = ['id' => $id, 'name' => (string) $row['target']];
                $contrast = $contrast || $uses;
            }
        }
        $classes = [];
        foreach ($this->db->table('style_classes')->select(['id', 'name', 'style'])->get() as $row) {
            $uses = $this->uses(ColorTokenWalker::KIND_CLASS, ['style' => self::json($row['style'] ?? null)], $slot);
            if ($uses !== null) {
                $classes[] = ['id' => (string) $row['id'], 'name' => (string) $row['name']];
                $contrast = $contrast || $uses;
            }
        }
        $blocking = [
            'entries' => array_values($entries),
            'regions' => array_values($regions),
            'layouts' => array_values($layouts),
            'saved_sections' => array_values($sections),
            'style_classes' => $classes,
        ];
        $total = array_sum(array_map('count', $blocking));
        return [
            'slot' => $slot,
            'blocking' => $blocking + ['total' => $total, 'contrast_references' => $contrast],
            'historical' => ['entries' => array_values($history), 'total' => count($history)],
        ];
    }

    public function blockingTotal(int $slot): int
    {
        return $this->of($slot)['blocking']['total'];
    }

    /**
     * Null when the document does not name the slot; else whether it names the slot's contrast token.
     *
     * @param array<string,mixed> $doc
     */
    private function uses(string $kind, array $doc, int $slot, ?ContentTypeSchema $schema = null): ?bool
    {
        $found = null;
        foreach ($this->walker->tokens($kind, $doc, $schema) as $token) {
            if (Palette::slotOf($token) === $slot) {
                $found = ($found ?? false) || Palette::isContrastToken($token);
            }
        }
        return $found;
    }

    /** @return array<string,mixed> */
    private static function json(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        return is_array($decoded) ? $decoded : [];
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

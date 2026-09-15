<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Style\Classes;

use Thallo\Contracts\Style\BlockStyleRegistry;
use Thallo\Contracts\Style\StyleSchema;
use Thallo\Core\Content\Blocks\Sources\BlockDocumentSource;
use Thallo\Core\Content\Blocks\Sources\BlockDocumentSources;
use Thallo\Core\Content\Blocks\Sources\DocumentRef;
use Thallo\Core\Content\Blocks\Sources\EntryDraftsSource;
use Thallo\Core\Content\Blocks\Sources\EntryVersionsSource;
use Thallo\Core\Content\Blocks\Sources\PublishedEntriesSource;
use Thallo\Core\Content\Blocks\Sources\RegionsSource;
use Glueful\Database\Connection;

/**
 * Where a style class is used (visual builder spec §4.3): every block-bearing document source,
 * one reference per occurrence of the id in one stored document. A retained version that is
 * also the current publication is counted once, under `entry_published`. Per reference, each
 * property the class declares is active where the block type has the capability and dormant
 * elsewhere — what the page shows before a save.
 */
final class StyleClassUsage
{
    public function __construct(
        private readonly BlockDocumentSources $sources,
        private readonly BlockStyleRegistry $registry,
        private readonly Connection $db,
    ) {
    }

    /**
     * @param array<string,mixed> $style the class's declarations
     * @return array{
     *   references: int,
     *   by_source: array{entry_drafts: int, entry_published: int, entry_versions: int, regions: int},
     *   active: int,
     *   dormant: int,
     *   properties: array<string, array{active: int, dormant: int}>
     * }
     */
    public function of(string $classId, array $style): array
    {
        $declared = self::declaredPaths($style);
        $bySource = ['entry_drafts' => 0, 'entry_published' => 0, 'entry_versions' => 0, 'regions' => 0];
        $properties = [];
        foreach ($declared as $path) {
            $properties[$path] = ['active' => 0, 'dormant' => 0];
        }
        $active = 0;
        $dormant = 0;
        $published = $this->publishedVersionUuids();
        $keys = [
            EntryDraftsSource::ID => 'entry_drafts',
            PublishedEntriesSource::ID => 'entry_published',
            EntryVersionsSource::ID => 'entry_versions',
            RegionsSource::ID => 'regions',
        ];
        $count = function (DocumentRef $ref) use (
            $classId,
            $declared,
            $keys,
            $published,
            &$bySource,
            &$properties,
            &$active,
            &$dormant,
        ): void {
            if ($ref->sourceType === EntryVersionsSource::ID && isset($published[$ref->sourceId])) {
                return; // the current publication: counted under entry_published
            }
            $key = $keys[$ref->sourceType] ?? null;
            if ($key === null) {
                return;
            }
            foreach ($this->referencingBlocks($ref, $classId) as $type) {
                $bySource[$key]++;
                $caps = $this->registry->capabilitiesFor($type);
                foreach ($declared as $path) {
                    if ($caps->allows($path)) {
                        $properties[$path]['active']++;
                        $active++;
                    } else {
                        $properties[$path]['dormant']++;
                        $dormant++;
                    }
                }
            }
        };
        $this->sources->each(static fn (BlockDocumentSource $source, DocumentRef $ref) => $count($ref));
        return [
            'references' => array_sum($bySource),
            'by_source' => $bySource,
            'active' => $active,
            'dormant' => $dormant,
            'properties' => $properties,
        ];
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

    /**
     * The types of the blocks in the document that reference the class, one per occurrence.
     *
     * @return list<string>
     */
    private function referencingBlocks(DocumentRef $ref, string $classId): array
    {
        $found = [];
        foreach ($ref->schema->fields() as $field) {
            if ($field->type === 'blocks') {
                $this->walk($ref->fields[$field->name] ?? null, $classId, $found);
            }
        }
        return $found;
    }

    /** @param list<string> $found */
    private function walk(mixed $list, string $classId, array &$found): void
    {
        if (!is_array($list)) {
            return;
        }
        foreach ($list as $block) {
            if (!is_array($block) || !is_string($block['type'] ?? null)) {
                continue;
            }
            $classes = $block['settings']['classes'] ?? null;
            if (is_array($classes) && in_array($classId, $classes, true)) {
                $found[] = $block['type'];
            }
            foreach ($this->registry->regionsFor($block['type']) as $slot) {
                $this->walk($block['data'][$slot] ?? null, $classId, $found);
            }
        }
    }

    /**
     * The §1.3 property paths a style declares, in table order.
     *
     * @param array<string,mixed> $style
     * @return list<string>
     */
    public static function declaredPaths(array $style): array
    {
        $paths = [];
        foreach (array_keys(StyleSchema::properties()) as $path) {
            $node = $style;
            foreach (explode('.', $path) as $part) {
                if (!is_array($node) || !array_key_exists($part, $node)) {
                    $node = null;
                    break;
                }
                $node = $node[$part];
            }
            if (is_array($node) && $node !== []) {
                $paths[] = $path;
            }
        }
        return $paths;
    }
}

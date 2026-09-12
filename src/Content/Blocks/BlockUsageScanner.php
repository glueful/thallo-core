<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Blocks;

use Thallo\Core\Content\Blocks\Migration\BlockInstanceWalker;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Glueful\Database\Connection;

/**
 * On-demand block-type usage scan (block-migrations spec §6) — "current content
 * that could become editable/live again": current drafts + pinned publications of
 * NON-DELETED entries (archived included), nested to the depth cap, all locales.
 * Historical versions never count (the restore projector fences them). Content-
 * type `block_types` picker allowlists are REPORTED, never gating. Admin cold
 * path — no projection table.
 */
final class BlockUsageScanner
{
    private const SAMPLE_LIMIT = 5;

    public function __construct(
        private readonly Connection $db,
        private readonly ContentTypeRepository $contentTypes,
        private readonly BlockInstanceWalker $walker,
    ) {
    }

    /**
     * @return array{
     *   total: int,
     *   per_type: list<array{type: string, drafts: int, publications: int,
     *     sample: list<array{entry_uuid: string, title: ?string}>}>,
     *   allowlists: list<string>
     * }
     */
    public function usage(string $slug): array
    {
        $total = 0;
        $perType = [];
        $allowlists = [];

        foreach ($this->contentTypes->all() as $ct) {
            $schema = ContentTypeSchema::fromArray((array) $ct['schema']);
            $ctSlug = (string) $ct['slug'];

            foreach ($schema->fields() as $field) {
                if ($field->type === 'blocks' && in_array($slug, $field->blockTypes, true)) {
                    $allowlists[] = $ctSlug;
                    break;
                }
            }

            if (!$this->hasBlocksField($schema)) {
                continue;
            }
            $typeUuid = (string) $ct['uuid'];

            $drafts = 0;
            $publications = 0;
            $sample = [];

            foreach (
                $this->db->table('entry_drafts as d')
                    ->join('entries as e', 'e.uuid', '=', 'd.entry_uuid')
                    ->select(['d.entry_uuid', 'd.fields'])
                    ->where('e.content_type_uuid', '=', $typeUuid)
                    ->where('e.status', '!=', 'deleted')
                    ->get() as $row
            ) {
                $fields = $this->decode($row['fields']);
                if (in_array($slug, $this->walker->slugsIn($fields, $schema), true)) {
                    $drafts++;
                    $this->collectSample($sample, (string) $row['entry_uuid'], $fields);
                }
            }
            foreach (
                $this->db->table('entry_publications as p')
                    ->join('entries as e', 'e.uuid', '=', 'p.entry_uuid')
                    ->join('entry_versions as v', 'v.uuid', '=', 'p.version_uuid')
                    ->select(['p.entry_uuid', 'v.fields'])
                    ->where('e.content_type_uuid', '=', $typeUuid)
                    ->where('e.status', '!=', 'deleted')
                    ->get() as $row
            ) {
                $fields = $this->decode($row['fields']);
                if (in_array($slug, $this->walker->slugsIn($fields, $schema), true)) {
                    $publications++;
                    $this->collectSample($sample, (string) $row['entry_uuid'], $fields);
                }
            }

            if ($drafts > 0 || $publications > 0) {
                $total += $drafts + $publications;
                $perType[] = [
                    'type' => $ctSlug,
                    'drafts' => $drafts,
                    'publications' => $publications,
                    'sample' => array_values($sample),
                ];
            }
        }

        return [
            'total' => $total,
            'per_type' => $perType,
            'allowlists' => $allowlists,
        ];
    }

    private function hasBlocksField(ContentTypeSchema $schema): bool
    {
        foreach ($schema->fields() as $field) {
            if ($field->type === 'blocks') {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<string,array{entry_uuid: string, title: ?string}> $sample
     * @param array<string,mixed> $fields
     */
    private function collectSample(array &$sample, string $entryUuid, array $fields): void
    {
        if (isset($sample[$entryUuid]) || count($sample) >= self::SAMPLE_LIMIT) {
            return;
        }
        $title = isset($fields['title']) && is_string($fields['title']) ? $fields['title'] : null;
        $sample[$entryUuid] = ['entry_uuid' => $entryUuid, 'title' => $title];
    }

    /** @return array<string,mixed> */
    private function decode(mixed $fields): array
    {
        if (is_string($fields)) {
            $decoded = json_decode($fields, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($fields) ? $fields : [];
    }
}

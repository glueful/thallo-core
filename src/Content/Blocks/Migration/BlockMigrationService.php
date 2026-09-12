<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Blocks\Migration;

use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Jobs\RunBlockBackfillJob;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Content\Schema\Migration\DeleteField;
use Thallo\Core\Content\Schema\Migration\MigrationOpSet;
use Thallo\Core\Content\Schema\Migration\RenameField;
use Thallo\Core\Content\Schema\SchemaParseException;
use Thallo\Core\Content\Services\ActiveMigrationException;
use Glueful\Database\Connection;
use Glueful\Queue\QueueManager;

/**
 * Declares a block-type migration (block-migrations spec §2): validates ops
 * against the CURRENT block schema (content-type collision rules), computes the
 * post-op schema, counts work items (current drafts + pinned publications of
 * NON-DELETED entries — archived included — containing op-source keys), records +
 * flips atomically, queues the backfill. One active (running|failed) migration
 * per block type.
 */
final class BlockMigrationService
{
    public function __construct(
        private readonly Connection $db,
        private readonly BlockTypeRepository $blockTypes,
        private readonly BlockMigrationRepository $migrations,
        private readonly ContentTypeRepository $contentTypes,
        private readonly BlockInstanceWalker $walker,
        private readonly QueueManager $queue,
    ) {
    }

    /** @param list<array<string,mixed>> $rawOps */
    public function migrate(string $blockTypeUuid, array $rawOps, ?string $actor): string
    {
        $type = $this->blockTypes->findByUuid($blockTypeUuid);
        if ($type === null) {
            throw new SchemaParseException("block type {$blockTypeUuid} not found");
        }
        if ($this->migrations->activeForType($blockTypeUuid) !== null) {
            throw new ActiveMigrationException(
                'a migration is already active for this block type (running or failed — re-drive it first)'
            );
        }

        $currentSchema = (array) $type['schema'];
        $opSet = $this->parseAndValidate($rawOps, $currentSchema);
        $newSchema = $this->computeNewSchema($currentSchema, $rawOps);
        $workItems = $this->countWorkItems((string) $type['slug'], $opSet);

        $uuid = $this->migrations->recordAndFlip($blockTypeUuid, $opSet, $newSchema, $workItems, $actor);

        $this->db->afterCommit(function () use ($uuid): void {
            $this->queue->push(RunBlockBackfillJob::class, ['migration_uuid' => $uuid]);
        });

        return $uuid;
    }

    /**
     * Count entries whose current draft OR pinned publication still carries an
     * op-source key inside an instance of the migrating type. NON-DELETED entries
     * (archived included — spec §4). The registry schema is only used for DESCENT
     * (blocks-field names), which the flip does not change — safe either side of
     * recordAndFlip.
     */
    private function countWorkItems(string $slug, MigrationOpSet $opSet): int
    {
        $count = 0;
        foreach ($this->contentTypes->all() as $ct) {
            $schema = ContentTypeSchema::fromArray((array) $ct['schema']);
            if (!$this->hasBlocksField($schema)) {
                continue;
            }
            $typeUuid = (string) $ct['uuid'];
            foreach (
                $this->db->table('entry_drafts as d')
                    ->join('entries as e', 'e.uuid', '=', 'd.entry_uuid')
                    ->select(['d.fields'])
                    ->where('e.content_type_uuid', '=', $typeUuid)
                    ->where('e.status', '!=', 'deleted')
                    ->get() as $row
            ) {
                if ($this->walker->hasOpSources($this->decode($row['fields']), $schema, $slug, $opSet)) {
                    $count++;
                }
            }
            foreach (
                $this->db->table('entry_publications as p')
                    ->join('entries as e', 'e.uuid', '=', 'p.entry_uuid')
                    ->join('entry_versions as v', 'v.uuid', '=', 'p.version_uuid')
                    ->select(['v.fields'])
                    ->where('e.content_type_uuid', '=', $typeUuid)
                    ->where('e.status', '!=', 'deleted')
                    ->get() as $row
            ) {
                if ($this->walker->hasOpSources($this->decode($row['fields']), $schema, $slug, $opSet)) {
                    $count++;
                }
            }
        }
        return $count;
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

    /** @return array<string,mixed> */
    private function decode(mixed $fields): array
    {
        if (is_string($fields)) {
            $decoded = json_decode($fields, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($fields) ? $fields : [];
    }

    /**
     * Verbatim transplant of MigrationService::parseAndValidate — same collision
     * rules, same error strings; ops operate on plain field lists.
     *
     * @param list<array<string,mixed>> $rawOps
     * @param list<array<string,mixed>> $currentSchema
     */
    private function parseAndValidate(array $rawOps, array $currentSchema): MigrationOpSet
    {
        $declared = [];
        foreach ($currentSchema as $field) {
            if (isset($field['name']) && is_string($field['name'])) {
                $declared[$field['name']] = true;
            }
        }

        $sources = [];
        $targets = [];
        $ops = [];
        foreach ($rawOps as $raw) {
            $kind = (string) ($raw['op'] ?? '');
            if ($kind === 'delete') {
                $name = (string) ($raw['name'] ?? '');
                if (!isset($declared[$name])) {
                    throw new SchemaParseException("cannot delete field '{$name}': not declared");
                }
                if (isset($sources[$name])) {
                    throw new SchemaParseException("field '{$name}' is the source/name of more than one op");
                }
                $sources[$name] = true;
                $ops[] = new DeleteField($name);
                continue;
            }

            if ($kind === 'rename') {
                $from = (string) ($raw['from'] ?? '');
                $to = (string) ($raw['to'] ?? '');
                if (!isset($declared[$from])) {
                    throw new SchemaParseException("cannot rename '{$from}': not declared");
                }
                if ($to === $from || isset($declared[$to])) {
                    throw new SchemaParseException("rename target '{$to}' collides with a declared field");
                }
                if (isset($sources[$from])) {
                    throw new SchemaParseException("field '{$from}' is the source/name of more than one op");
                }
                if (isset($targets[$to])) {
                    throw new SchemaParseException("duplicate target '{$to}' in ops");
                }
                $sources[$from] = true;
                $targets[$to] = true;
                $ops[] = new RenameField($from, $to);
                continue;
            }

            throw new SchemaParseException("unknown migration op '{$kind}'");
        }

        if ($ops === []) {
            throw new SchemaParseException('migration must contain at least one op');
        }

        return new MigrationOpSet($ops);
    }

    /**
     * @param list<array<string,mixed>> $currentSchema
     * @param list<array<string,mixed>> $rawOps
     * @return list<array<string,mixed>>
     */
    private function computeNewSchema(array $currentSchema, array $rawOps): array
    {
        $deleted = [];
        $renames = [];
        foreach ($rawOps as $raw) {
            if (($raw['op'] ?? '') === 'delete') {
                $deleted[(string) $raw['name']] = true;
                continue;
            }
            if (($raw['op'] ?? '') === 'rename') {
                $renames[(string) $raw['from']] = (string) $raw['to'];
            }
        }

        $out = [];
        foreach ($currentSchema as $field) {
            $name = (string) ($field['name'] ?? '');
            if (isset($deleted[$name])) {
                continue;
            }
            if (isset($renames[$name])) {
                $field['name'] = $renames[$name];
            }
            $out[] = $field;
        }

        return array_values($out);
    }
}

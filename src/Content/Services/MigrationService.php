<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Services;

use Thallo\Core\Content\Jobs\RunBackfillJob;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\MigrationRepository;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Content\Schema\Migration\DeleteField;
use Thallo\Core\Content\Schema\Migration\MigrationOpSet;
use Thallo\Core\Content\Schema\Migration\RenameField;
use Thallo\Core\Content\Schema\SchemaParseException;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Queue\QueueManager;

final class MigrationService
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly Connection $db,
        private readonly ContentTypeRepository $types,
        private readonly MigrationRepository $migrations,
        private readonly QueueManager $queue,
    ) {
    }

    /**
     * @param list<array<string,mixed>> $rawOps
     */
    public function migrate(string $contentTypeUuid, array $rawOps, ?string $actor): string
    {
        $type = $this->types->findByUuid($contentTypeUuid);
        if ($type === null) {
            throw new SchemaParseException("content type {$contentTypeUuid} not found");
        }
        if ($this->migrations->activeForType($contentTypeUuid) !== null) {
            throw new ActiveMigrationException('a migration is already in progress for this content type');
        }

        $currentSchema = (array) $type['schema'];
        $opSet = $this->parseAndValidate($rawOps, $currentSchema);
        $newSchema = $this->computeNewSchema($currentSchema, $rawOps);
        $parsed = ContentTypeSchema::fromArray($newSchema);
        $fromVersion = (int) $type['schema_version'];
        $workItems = $this->countWorkItems($contentTypeUuid);

        $uuid = $this->migrations->recordAndFlip(
            $contentTypeUuid,
            $fromVersion,
            $opSet,
            $parsed->toArray(),
            $workItems,
            $actor,
        );

        $this->db->afterCommit(function () use ($uuid): void {
            $this->queue->push(RunBackfillJob::class, ['migration_uuid' => $uuid]);
        });

        return $uuid;
    }

    /**
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

    private function countWorkItems(string $contentTypeUuid): int
    {
        $drafts = $this->db->table('entry_drafts as d')
            ->join('entries as e', 'e.uuid', '=', 'd.entry_uuid')
            ->where('e.content_type_uuid', '=', $contentTypeUuid)
            ->where('e.status', '=', 'active')
            ->count();
        $publications = $this->db->table('entry_publications as p')
            ->join('entries as e', 'e.uuid', '=', 'p.entry_uuid')
            ->where('e.content_type_uuid', '=', $contentTypeUuid)
            ->where('e.status', '=', 'active')
            ->count();

        return (int) $drafts + (int) $publications;
    }
}

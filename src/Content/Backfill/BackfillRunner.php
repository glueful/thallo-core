<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Backfill;

use Thallo\Core\Content\Indexing\FilterIndexJobDispatcher;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\MigrationRepository;
use Thallo\Core\Content\Repositories\ReferenceProjectionRepository;
use Thallo\Core\Content\Repositories\VersionRepository;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Content\Schema\Migration\MigrationOpSet;
use Glueful\Cache\CacheStore;
use Glueful\Database\Connection;
use Psr\Container\ContainerInterface;
use Thallo\Contracts\Tenancy\WriteBarrier;

final class BackfillRunner
{
    public function __construct(
        private readonly Connection $db,
        private readonly MigrationRepository $migrations,
        private readonly ContentTypeRepository $types,
        private readonly VersionRepository $versions,
        private readonly ReferenceProjectionRepository $references,
        private readonly FilterIndexJobDispatcher $filterIndexes,
        private readonly ContainerInterface $container,
        private readonly ?WriteBarrier $barrier = null,
    ) {
    }

    /** @return array{done:int,failed:int} */
    public function run(string $migrationUuid): array
    {
        $this->barrier?->assertWritable();
        $migration = $this->migrations->find($migrationUuid);
        if ($migration === null) {
            throw new \RuntimeException("migration {$migrationUuid} not found");
        }

        $typeUuid = (string) $migration['content_type_uuid'];
        $toVersion = (int) $migration['to_version'];
        $opSet = MigrationOpSet::fromArray($migration['ops']);
        $schema = $this->types->schemaFor($typeUuid);
        $actor = $migration['created_by'] === null ? null : (string) $migration['created_by'];

        $this->migrations->resetFailures($migrationUuid);

        foreach ($this->draftItems($typeUuid, $toVersion) as $item) {
            $this->processDraft($migrationUuid, $opSet, $toVersion, $item);
        }
        foreach ($this->publishedItems($typeUuid, $toVersion) as $item) {
            $this->processPublished($migrationUuid, $opSet, $schema, $toVersion, $actor, $item);
        }

        $this->filterIndexes->dispatch($typeUuid);
        $this->invalidateCache($typeUuid);

        $remaining = count($this->draftItems($typeUuid, $toVersion))
            + count($this->publishedItems($typeUuid, $toVersion));
        $this->migrations->finish($migrationUuid, $remaining === 0 ? 'completed' : 'failed');

        $row = $this->migrations->find($migrationUuid);
        return [
            'done' => (int) ($row['work_items_done'] ?? 0),
            'failed' => (int) ($row['work_items_failed'] ?? 0),
        ];
    }

    /**
     * @param array<string,mixed> $item
     */
    private function processDraft(string $migrationUuid, MigrationOpSet $opSet, int $toVersion, array $item): void
    {
        $entry = (string) $item['entry_uuid'];
        $locale = (string) $item['locale'];
        $expectedLock = (int) $item['lock_version'];

        try {
            $fields = $this->decodeFields($item['fields'] ?? []);
            $migrated = $opSet->apply($fields);
            // Optimistic CAS mirroring EntryRepository::saveDraft: only migrate the row we read.
            // The lock_version guard makes a concurrent editor save (which bumps lock_version) lose
            // the race here instead of being silently overwritten; the schema_version guard stops a
            // second pass from re-applying the op-set. Bumping lock_version means an editor still
            // holding the pre-migration draft gets a 409 on their next save and re-syncs — the
            // correct optimistic-lock behaviour, not a lost update.
            $affected = $this->db->table('entry_drafts')
                ->where('entry_uuid', '=', $entry)
                ->where('locale', '=', $locale)
                ->where('lock_version', '=', $expectedLock)
                ->where('schema_version', '<', $toVersion)
                ->update([
                    'fields' => json_encode($migrated, JSON_THROW_ON_ERROR),
                    'schema_version' => $toVersion,
                    'lock_version' => $expectedLock + 1,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            if ($affected < 1) {
                // The draft changed under us (editor save) or was already migrated. Do NOT clobber
                // the newer content. If it still sits below the target it's genuinely remaining —
                // record a re-runnable failure so the end-of-run recount marks the migration
                // 'failed'; if it was concurrently migrated/deleted it isn't remaining, so stay
                // quiet (the recount is authoritative either way).
                $current = $this->db->table('entry_drafts')
                    ->select(['schema_version'])
                    ->where('entry_uuid', '=', $entry)
                    ->where('locale', '=', $locale)
                    ->first();
                if ($current !== null && (int) $current['schema_version'] < $toVersion) {
                    $this->migrations->recordFailure(
                        $migrationUuid,
                        $entry,
                        $locale,
                        'draft',
                        'draft changed concurrently during backfill; re-run to migrate the latest content',
                    );
                }
                return;
            }
            $this->migrations->incrementDone($migrationUuid);
        } catch (\Throwable $e) {
            $this->migrations->recordFailure($migrationUuid, $entry, $locale, 'draft', $e->getMessage());
        }
    }

    /**
     * @param array<string,mixed> $item
     */
    private function processPublished(
        string $migrationUuid,
        MigrationOpSet $opSet,
        ContentTypeSchema $schema,
        int $toVersion,
        ?string $actor,
        array $item,
    ): void {
        $entry = (string) $item['entry_uuid'];
        $locale = (string) $item['locale'];

        try {
            $version = $this->versions->findVersionByUuid((string) $item['version_uuid']);
            if ($version === null) {
                throw new \RuntimeException('pinned version missing');
            }
            $migrated = $opSet->apply((array) $version['fields']);
            $pinnedVersionUuid = (string) $item['version_uuid'];

            $skipped = false;
            $this->db->transaction(function () use (
                $entry,
                $locale,
                $migrated,
                $toVersion,
                $actor,
                $schema,
                $pinnedVersionUuid,
                &$skipped,
            ): void {
                // Acquire the per-(entry,locale) advisory lock FIRST (same lock PublishService takes),
                // then re-read the pin under it. If a concurrent publish/unpublish moved the pin after
                // publishedItems() read it, re-pinning a migrated copy of the now-stale version would
                // revert that publish — so skip instead. The reserved number is simply not used.
                $number = $this->versions->reserveNextVersionNumber($entry, $locale);
                $current = $this->versions->findPublication($entry, $locale);
                if ($current === null || (string) $current['version_uuid'] !== $pinnedVersionUuid) {
                    $skipped = true;
                    return;
                }
                $newUuid = $this->versions->appendVersion($entry, $locale, $number, $migrated, $toVersion, $actor);
                $this->versions->pin($entry, $locale, $newUuid, $actor);
                $this->references->rebuildForEntry($entry, $schema, $migrated, $locale);
            });

            if ($skipped) {
                // Not counted as done; the end-of-run recount decides completion. The concurrently
                // published version carries the current schema, so it won't be remaining.
                return;
            }
            $this->migrations->incrementDone($migrationUuid);
        } catch (\Throwable $e) {
            $this->migrations->recordFailure($migrationUuid, $entry, $locale, 'published', $e->getMessage());
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function draftItems(string $typeUuid, int $toVersion): array
    {
        return $this->db->table('entry_drafts as d')
            ->join('entries as e', 'e.uuid', '=', 'd.entry_uuid')
            ->select(['d.entry_uuid', 'd.locale', 'd.fields', 'd.schema_version', 'd.lock_version'])
            ->where('e.content_type_uuid', '=', $typeUuid)
            ->where('e.status', '=', 'active')
            ->where('d.schema_version', '<', $toVersion)
            ->get();
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function publishedItems(string $typeUuid, int $toVersion): array
    {
        return $this->db->table('entry_publications as p')
            ->join('entries as e', 'e.uuid', '=', 'p.entry_uuid')
            ->join('entry_versions as v', 'v.uuid', '=', 'p.version_uuid')
            ->select(['p.entry_uuid', 'p.locale', 'p.version_uuid', 'v.schema_version'])
            ->where('e.content_type_uuid', '=', $typeUuid)
            ->where('e.status', '=', 'active')
            ->where('v.schema_version', '<', $toVersion)
            ->get();
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeFields(mixed $fields): array
    {
        if (is_string($fields)) {
            $decoded = json_decode($fields, true);
            return is_array($decoded) ? $decoded : [];
        }

        return is_array($fields) ? $fields : [];
    }

    private function invalidateCache(string $typeUuid): void
    {
        $type = $this->types->findByUuid($typeUuid);
        if ($type === null || !$this->container->has(CacheStore::class)) {
            return;
        }

        /** @var CacheStore $cache */
        $cache = $this->container->get(CacheStore::class);
        $cache->invalidateTags(['thallo:type:' . (string) $type['slug']]);
    }
}

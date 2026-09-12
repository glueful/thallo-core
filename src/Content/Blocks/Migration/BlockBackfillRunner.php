<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Blocks\Migration;

use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\ReferenceProjectionRepository;
use Thallo\Core\Content\Repositories\VersionRepository;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Content\Schema\Migration\MigrationOpSet;
use Glueful\Cache\CacheStore;
use Glueful\Database\Connection;
use Psr\Container\ContainerInterface;
use Thallo\Contracts\Tenancy\WriteBarrier;

/**
 * Eager block-schema backfill (block-migrations spec §4) — BackfillRunner's SHAPE
 * with the block deltas: the work predicate is `entries.status != 'deleted'`
 * (archived entries can return to current content; the content-type runner's
 * active-only filter would strand un-migrated instances behind the write gate),
 * and "remaining work" is op-source presence via the shared walker (block
 * instances carry no schema stamp). Drafts rewrite in place under a lock CAS;
 * publications append a NEW version and repin (append-only versioning holds, and
 * the republished version's µs created_at postdates the migration row — the
 * restore suffix relies on that).
 */
final class BlockBackfillRunner
{
    public function __construct(
        private readonly Connection $db,
        private readonly BlockMigrationRepository $migrations,
        private readonly BlockTypeRepository $blockTypes,
        private readonly ContentTypeRepository $contentTypes,
        private readonly VersionRepository $versions,
        private readonly ReferenceProjectionRepository $references,
        private readonly BlockInstanceWalker $walker,
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
            throw new \RuntimeException("block migration {$migrationUuid} not found");
        }
        $blockType = $this->blockTypes->findByUuid((string) $migration['block_type_uuid']);
        if ($blockType === null) {
            throw new \RuntimeException('block type for migration no longer exists');
        }
        $slug = (string) $blockType['slug'];
        $opSet = MigrationOpSet::fromArray($migration['ops']);
        $actor = $migration['created_by'] === null ? null : (string) $migration['created_by'];

        $this->migrations->resetFailures($migrationUuid);

        $touchedTypeSlugs = [];
        foreach ($this->blockContentTypes() as $ct) {
            $schema = ContentTypeSchema::fromArray((array) $ct['schema']);
            $typeUuid = (string) $ct['uuid'];
            $hadWork = false;

            foreach ($this->draftItems($typeUuid) as $item) {
                if (!$this->walker->hasOpSources($this->decodeFields($item['fields']), $schema, $slug, $opSet)) {
                    continue;
                }
                $hadWork = true;
                $this->processDraft($migrationUuid, $slug, $opSet, $schema, $item);
            }
            foreach ($this->publishedItems($typeUuid) as $item) {
                $version = $this->versions->findVersionByUuid((string) $item['version_uuid']);
                $fields = $version === null ? [] : (array) $version['fields'];
                if (!$this->walker->hasOpSources($fields, $schema, $slug, $opSet)) {
                    continue;
                }
                $hadWork = true;
                $this->processPublished(
                    $migrationUuid,
                    $slug,
                    $opSet,
                    $schema,
                    (int) ($ct['schema_version'] ?? 1),
                    $actor,
                    $item,
                );
            }

            if ($hadWork) {
                $touchedTypeSlugs[(string) $ct['slug']] = true;
            }
        }

        $remaining = $this->countRemaining($slug, $opSet);
        $this->migrations->finish($migrationUuid, $remaining === 0 ? 'completed' : 'failed');
        $this->invalidateCache(array_keys($touchedTypeSlugs));

        $row = $this->migrations->find($migrationUuid);
        return [
            'done' => (int) ($row['work_items_done'] ?? 0),
            'failed' => (int) ($row['work_items_failed'] ?? 0),
        ];
    }

    /**
     * @param array<string,mixed> $item
     */
    private function processDraft(
        string $migrationUuid,
        string $slug,
        MigrationOpSet $opSet,
        ContentTypeSchema $schema,
        array $item,
    ): void {
        $entry = (string) $item['entry_uuid'];
        $locale = (string) $item['locale'];
        $expectedLock = (int) $item['lock_version'];

        try {
            [$migrated, $changed] = $this->walker->rewrite(
                $this->decodeFields($item['fields']),
                $schema,
                $slug,
                $opSet,
            );
            if (!$changed) {
                return;
            }
            // Optimistic CAS mirroring EntryRepository::saveDraft: only migrate the
            // row we read. There is NO schema_version guard here (block instances
            // are unstamped) — idempotence comes from tolerant ops + the op-source
            // recount; a raced editor save loses nothing (their lock bump makes the
            // CAS miss and the failure is re-drivable).
            $affected = $this->db->table('entry_drafts')
                ->where('entry_uuid', '=', $entry)
                ->where('locale', '=', $locale)
                ->where('lock_version', '=', $expectedLock)
                ->update([
                    'fields' => json_encode($migrated, JSON_THROW_ON_ERROR),
                    'lock_version' => $expectedLock + 1,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
            if ($affected < 1) {
                $current = $this->db->table('entry_drafts')
                    ->select(['fields'])
                    ->where('entry_uuid', '=', $entry)
                    ->where('locale', '=', $locale)
                    ->first();
                $stillRemaining = $current !== null
                    && $this->walker->hasOpSources($this->decodeFields($current['fields']), $schema, $slug, $opSet);
                if ($stillRemaining) {
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
        string $slug,
        MigrationOpSet $opSet,
        ContentTypeSchema $schema,
        int $contentTypeSchemaVersion,
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
            [$migrated, $changed] = $this->walker->rewrite((array) $version['fields'], $schema, $slug, $opSet);
            if (!$changed) {
                return;
            }
            $pinnedVersionUuid = (string) $item['version_uuid'];

            $skipped = false;
            $this->db->transaction(function () use (
                $entry,
                $locale,
                $migrated,
                $contentTypeSchemaVersion,
                $actor,
                $schema,
                $pinnedVersionUuid,
                &$skipped,
            ): void {
                // Advisory lock first (same lock PublishService takes), then re-read
                // the pin under it: a concurrent publish moved on -> skip, never
                // revert (BackfillRunner::processPublished parity).
                $number = $this->versions->reserveNextVersionNumber($entry, $locale);
                $current = $this->versions->findPublication($entry, $locale);
                if ($current === null || (string) $current['version_uuid'] !== $pinnedVersionUuid) {
                    $skipped = true;
                    return;
                }
                $newUuid = $this->versions->appendVersion(
                    $entry,
                    $locale,
                    $number,
                    $migrated,
                    $contentTypeSchemaVersion,
                    $actor,
                );
                $this->versions->pin($entry, $locale, $newUuid, $actor);
                $this->references->rebuildForEntry($entry, $schema, $migrated, $locale);
            });

            if ($skipped) {
                return;
            }
            $this->migrations->incrementDone($migrationUuid);
        } catch (\Throwable $e) {
            $this->migrations->recordFailure($migrationUuid, $entry, $locale, 'published', $e->getMessage());
        }
    }

    /** @return list<array<string,mixed>> content types with at least one blocks field */
    private function blockContentTypes(): array
    {
        $out = [];
        foreach ($this->contentTypes->all() as $ct) {
            $schema = ContentTypeSchema::fromArray((array) $ct['schema']);
            foreach ($schema->fields() as $field) {
                if ($field->type === 'blocks') {
                    $out[] = $ct;
                    break;
                }
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function draftItems(string $typeUuid): array
    {
        return $this->db->table('entry_drafts as d')
            ->join('entries as e', 'e.uuid', '=', 'd.entry_uuid')
            ->select(['d.entry_uuid', 'd.locale', 'd.fields', 'd.lock_version'])
            ->where('e.content_type_uuid', '=', $typeUuid)
            ->where('e.status', '!=', 'deleted')
            ->get();
    }

    /** @return list<array<string,mixed>> */
    private function publishedItems(string $typeUuid): array
    {
        return $this->db->table('entry_publications as p')
            ->join('entries as e', 'e.uuid', '=', 'p.entry_uuid')
            ->select(['p.entry_uuid', 'p.locale', 'p.version_uuid'])
            ->where('e.content_type_uuid', '=', $typeUuid)
            ->where('e.status', '!=', 'deleted')
            ->get();
    }

    /** End-of-run recount — the authoritative completion check. */
    private function countRemaining(string $slug, MigrationOpSet $opSet): int
    {
        $remaining = 0;
        foreach ($this->blockContentTypes() as $ct) {
            $schema = ContentTypeSchema::fromArray((array) $ct['schema']);
            $typeUuid = (string) $ct['uuid'];
            foreach ($this->draftItems($typeUuid) as $item) {
                if ($this->walker->hasOpSources($this->decodeFields($item['fields']), $schema, $slug, $opSet)) {
                    $remaining++;
                }
            }
            foreach ($this->publishedItems($typeUuid) as $item) {
                $version = $this->versions->findVersionByUuid((string) $item['version_uuid']);
                $fields = $version === null ? [] : (array) $version['fields'];
                if ($this->walker->hasOpSources($fields, $schema, $slug, $opSet)) {
                    $remaining++;
                }
            }
        }
        return $remaining;
    }

    /** @return array<string,mixed> */
    private function decodeFields(mixed $fields): array
    {
        if (is_string($fields)) {
            $decoded = json_decode($fields, true);
            return is_array($decoded) ? $decoded : [];
        }
        return is_array($fields) ? $fields : [];
    }

    /** @param list<string> $typeSlugs */
    private function invalidateCache(array $typeSlugs): void
    {
        if ($typeSlugs === [] || !$this->container->has(CacheStore::class)) {
            return;
        }
        /** @var CacheStore $cache */
        $cache = $this->container->get(CacheStore::class);
        $cache->invalidateTags(array_map(
            static fn(string $slug): string => 'thallo:type:' . $slug,
            $typeSlugs,
        ));
    }
}

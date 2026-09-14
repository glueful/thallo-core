<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Blocks\Migration;

use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Blocks\Sources\BlockDocumentSource;
use Thallo\Core\Content\Blocks\Sources\BlockDocumentSources;
use Thallo\Core\Content\Blocks\Sources\DocumentRef;
use Thallo\Core\Content\Blocks\Sources\EntryDraftsSource;
use Thallo\Core\Content\Blocks\Sources\PublishedEntriesSource;
use Thallo\Core\Content\Blocks\Sources\RegionsSource;
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
        private readonly BlockInstanceWalker $walker,
        private readonly ContainerInterface $container,
        private BlockDocumentSources $sources,
        private readonly ?WriteBarrier $barrier = null,
    ) {
    }

    /** @return array{done:int,failed:int} */
    /** The runner over another registry (tests substitute a source that loses every write). */
    public function withSources(BlockDocumentSources $sources): self
    {
        $clone = clone $this;
        $clone->sources = $sources;
        return $clone;
    }

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

        $this->migrations->resetFailures($migrationUuid);

        // Every block-bearing document a migration rewrites (visual builder plan A4.4): drafts,
        // the published version (append-and-repin — older versions keep their era for the restore
        // projection) and the regions, each persisted only while it is still what was read.
        $actor = $migration['created_by'] === null ? null : (string) $migration['created_by'];
        $touchedTypeSlugs = [];
        $this->migrationSources()->each(function (
            BlockDocumentSource $source,
            DocumentRef $ref,
        ) use (
            $migrationUuid,
            $slug,
            $opSet,
            $actor,
            &$touchedTypeSlugs,
        ): void {
            if (!$this->walker->hasOpSources($ref->fields, $ref->schema, $slug, $opSet)) {
                return;
            }
            if (isset($ref->meta['content_type'])) {
                $touchedTypeSlugs[(string) $ref->meta['content_type']] = true;
            }
            $this->process($migrationUuid, $slug, $opSet, $source, $ref, $actor);
        });

        $remaining = $this->countRemaining($slug, $opSet);
        $this->migrations->finish($migrationUuid, $remaining === 0 ? 'completed' : 'failed');
        $this->invalidateCache(array_keys($touchedTypeSlugs));

        $row = $this->migrations->find($migrationUuid);
        return [
            'done' => (int) ($row['work_items_done'] ?? 0),
            'failed' => (int) ($row['work_items_failed'] ?? 0),
        ];
    }

    /** The sources a migration rewrites; the registry may hold more (the converter's). */
    private function migrationSources(): BlockDocumentSources
    {
        return $this->sources->only(EntryDraftsSource::ID, PublishedEntriesSource::ID, RegionsSource::ID);
    }

    private function process(
        string $migrationUuid,
        string $slug,
        MigrationOpSet $opSet,
        BlockDocumentSource $source,
        DocumentRef $ref,
        ?string $actor,
    ): void {
        $entry = (string) ($ref->meta['entry_uuid'] ?? $ref->sourceId);
        $locale = (string) ($ref->locale ?? '');
        try {
            [$migrated, $changed] = $this->walker->rewrite($ref->fields, $ref->schema, $slug, $opSet);
            if (!$changed) {
                return;
            }
            if (!$source->persist($ref, $migrated, $actor)) {
                $this->migrations->recordFailure(
                    $migrationUuid,
                    $entry,
                    $locale,
                    $source->id(),
                    'document changed concurrently during backfill; re-run to migrate the latest content',
                );
                return;
            }
            $this->migrations->incrementDone($migrationUuid);
        } catch (\Throwable $e) {
            $this->migrations->recordFailure($migrationUuid, $entry, $locale, $source->id(), $e->getMessage());
        }
    }

    /** End-of-run recount over every source — the authoritative completion check. */
    private function countRemaining(string $slug, MigrationOpSet $opSet): int
    {
        $remaining = 0;
        $this->migrationSources()->each(function (
            BlockDocumentSource $source,
            DocumentRef $ref,
        ) use (
            $slug,
            $opSet,
            &$remaining,
        ): void {
            if ($this->walker->hasOpSources($ref->fields, $ref->schema, $slug, $opSet)) {
                $remaining++;
            }
        });
        return $remaining;
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

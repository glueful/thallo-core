<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Blocks;

use Thallo\Core\Content\Blocks\Migration\BlockInstanceWalker;
use Thallo\Core\Content\Blocks\Migration\BlockMigrationRepository;
use Thallo\Core\Content\Blocks\Migration\UnknownBlockTypeException;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Content\Schema\Migration\MigrationOpSet;

/**
 * One-shot restore projection (block-migrations spec §5): a version created
 * BEFORE a block migration carries pre-migration keys; re-pinning it verbatim
 * would reintroduce stale keys into current content. For each block type present
 * in the restored fields, apply the COMPLETED timestamp suffix
 * (migration.created_at > version.created_at, ASC — microsecond precision,
 * strict >). Ops are tolerant, but only within the suffix — never the full chain
 * (rename-chain reuse is the documented corruption case). An unknown slug blocks
 * BEFORE any write.
 */
final class BlockRestoreProjector
{
    public function __construct(
        private readonly BlockTypeRepository $registry,
        private readonly BlockMigrationRepository $migrations,
        private readonly BlockInstanceWalker $walker,
    ) {
    }

    /**
     * @param array<string,mixed> $fields
     * @return array{0: array<string,mixed>, 1: bool} [projected fields, changed]
     * @throws UnknownBlockTypeException
     */
    public function project(array $fields, ContentTypeSchema $schema, string $versionCreatedAt): array
    {
        $present = $this->walker->slugsIn($fields, $schema);
        if ($present === []) {
            return [$fields, false];
        }
        $byUuid = [];
        foreach ($present as $slug) {
            // findBySlug is DB truth — deliberately NOT schemasBySlug(), whose
            // per-instance memo can hold a type another instance hard-deleted.
            $row = $this->registry->findBySlug($slug);
            if ($row === null) {
                throw new UnknownBlockTypeException($slug);
            }
            $byUuid[$slug] = (string) $row['uuid'];
        }

        $changed = false;
        foreach ($byUuid as $slug => $typeUuid) {
            foreach ($this->migrations->completedAfter($typeUuid, $versionCreatedAt) as $migration) {
                [$fields, $stepChanged] = $this->walker->rewrite(
                    $fields,
                    $schema,
                    $slug,
                    MigrationOpSet::fromArray((array) $migration['ops']),
                );
                $changed = $changed || $stepChanged;
            }
        }
        return [$fields, $changed];
    }
}

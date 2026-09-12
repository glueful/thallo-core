<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Blocks;

use Thallo\Core\Content\Blocks\Migration\BlockInstanceWalker;
use Thallo\Core\Content\Blocks\Migration\BlockMigrationInProgressException;
use Thallo\Core\Content\Blocks\Migration\BlockMigrationRepository;
use Thallo\Core\Content\Schema\ContentTypeSchema;

/**
 * The migration write gate (block-migrations spec §3): block instances carry no
 * schema stamp, so a write against a flipped-but-unconverged schema is the exact
 * silent-strip data-loss path (old keys become unknown to the cleaned payload).
 * While a migration is ACTIVE (running OR failed), saving/publishing an entry
 * containing that block type must 409. Cheap first: ONE query (usually empty);
 * the structural walk runs only when a migration is live. The backfill's own
 * republish path bypasses PublishService entirely, so the gate can never
 * deadlock a migration's convergence.
 */
final class BlockMigrationGate
{
    public function __construct(
        private readonly BlockMigrationRepository $migrations,
        private readonly BlockInstanceWalker $walker,
    ) {
    }

    /**
     * @param array<string,mixed> $fields the SAVE payload, or the stored draft's
     *        fields on PUBLISH (publish has no payload)
     * @throws BlockMigrationInProgressException
     */
    public function assertWritable(array $fields, ContentTypeSchema $schema): void
    {
        $active = $this->migrations->activeAny();
        if ($active === []) {
            return;
        }
        $present = $this->walker->slugsIn($fields, $schema);
        foreach ($active as $migration) {
            if (in_array($migration['slug'], $present, true)) {
                throw new BlockMigrationInProgressException($migration['slug']);
            }
        }
    }
}

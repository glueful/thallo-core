<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Blocks\Migration;

/**
 * The write gate's signal (block-migrations spec §3): the entry being saved or
 * published contains a block type whose migration is ACTIVE (running or failed).
 * Controllers map it to 409 with code BLOCK_MIGRATION_IN_PROGRESS.
 */
final class BlockMigrationInProgressException extends \RuntimeException
{
    public function __construct(public readonly string $slug)
    {
        parent::__construct("block type '{$slug}' has a migration in progress");
    }
}

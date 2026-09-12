<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Builders\SchemaBuilder;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Add a per-run lease token to entry_schedules.
 *
 * The scheduler claims due rows (pending -> processing), fires the action, then writes the terminal
 * outcome in a separate statement; a stale-lease reclaim can flip a still-processing row back to
 * pending. Without an owner token, markOutcome/reclaim only key on status='processing', so a row
 * reclaimed by a second runner could have its outcome written by the original runner (and vice
 * versa). `locked_by` stamps which run owns a claimed row; reclaim clears it and markOutcome scopes
 * to it, so only the current owner can finalise a row.
 */
final class AddLockedByToEntrySchedules implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('entry_schedules')) {
            return;
        }
        if (!$schema instanceof SchemaBuilder) {
            throw new \RuntimeException('entry_schedules migration requires the Glueful SchemaBuilder.');
        }

        $schema->getConnection()->getPDO()->exec(
            'ALTER TABLE entry_schedules ADD COLUMN IF NOT EXISTS locked_by VARCHAR(64)'
        );
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        if (!$schema instanceof SchemaBuilder) {
            return;
        }
        $schema->getConnection()->getPDO()->exec(
            'ALTER TABLE entry_schedules DROP COLUMN IF EXISTS locked_by'
        );
    }

    public function getDescription(): string
    {
        return 'Add locked_by lease token to entry_schedules for safe reclaim/outcome scoping.';
    }
}

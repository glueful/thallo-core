<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Visual builder (spec §4.5): every block-bearing document persists through a conditional
 * update on a `lock_version` the reader handed out, so a concurrent change is a refused write,
 * never a lost one. Drafts carried one already; regions and retained versions gain it here,
 * and every writer bumps it.
 */
final class LockVersionOnRegionsAndEntryVersions implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        foreach (['regions', 'entry_versions'] as $table) {
            if (!$schema->hasTable($table) || $schema->hasColumn($table, 'lock_version')) {
                continue;
            }
            $schema->alterTable($table, function ($t): void {
                $t->integer('lock_version')->default(0);
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        foreach (['regions', 'entry_versions'] as $table) {
            if (!$schema->hasTable($table) || !$schema->hasColumn($table, 'lock_version')) {
                continue;
            }
            $schema->alterTable($table, function ($t): void {
                $t->dropColumn('lock_version');
            });
        }
    }

    public function getDescription(): string
    {
        return 'Regions and retained versions carry a lock_version for conditional writes.';
    }
}

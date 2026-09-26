<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Saved sections are block documents like drafts, versions and regions, so the walkers over stored
 * blocks — a block type's migration, a style class's detach- and remove-everywhere — rewrite them
 * too. Each of those writes is conditional on the `lock_version` the walker read, and every other
 * writer (a rename) bumps it, so a concurrent change is a refused write, never a lost one.
 */
final class LockVersionOnSavedSections implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('saved_sections') || $schema->hasColumn('saved_sections', 'lock_version')) {
            return;
        }
        $schema->alterTable('saved_sections', function ($t): void {
            $t->integer('lock_version')->default(0);
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('saved_sections') || !$schema->hasColumn('saved_sections', 'lock_version')) {
            return;
        }
        $schema->alterTable('saved_sections', function ($t): void {
            $t->dropColumn('lock_version');
        });
    }

    public function getDescription(): string
    {
        return 'Saved sections carry a lock_version for conditional writes by block walkers.';
    }
}

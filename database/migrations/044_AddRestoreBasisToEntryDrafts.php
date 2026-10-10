<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Custom palette (spec §4.5): a draft restored from a version keeps the brand colours that version
 * held, by block, as a trusted basis for its later saves — derived on the server when the restore
 * runs, so version retention can never take it away. Cleared by publish, discard or another restore.
 */
final class AddRestoreBasisToEntryDrafts implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('entry_drafts') || $schema->hasColumn('entry_drafts', 'restore_basis')) {
            return;
        }
        $schema->alterTable('entry_drafts', function ($t): void {
            $t->json('restore_basis')->nullable();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('entry_drafts') || !$schema->hasColumn('entry_drafts', 'restore_basis')) {
            return;
        }
        $schema->alterTable('entry_drafts', function ($t): void {
            $t->dropColumn('restore_basis');
        });
    }

    public function getDescription(): string
    {
        return 'A restored draft keeps its restored brand colours as a trusted basis.';
    }
}

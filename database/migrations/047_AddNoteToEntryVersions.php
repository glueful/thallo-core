<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Custom palette (spec §4.4): a version an operation appended rather than an editor — Replace's
 * "Replaced Gold dark with Accent" — says so. Null for an ordinary publish.
 */
final class AddNoteToEntryVersions implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('entry_versions') || $schema->hasColumn('entry_versions', 'note')) {
            return;
        }
        $schema->alterTable('entry_versions', function ($t): void {
            $t->string('note', 255)->nullable();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('entry_versions') || !$schema->hasColumn('entry_versions', 'note')) {
            return;
        }
        $schema->alterTable('entry_versions', function ($t): void {
            $t->dropColumn('note');
        });
    }

    public function getDescription(): string
    {
        return 'A version an operation appended carries a note saying which.';
    }
}

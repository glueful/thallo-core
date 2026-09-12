<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Make the reference projection (entry_references) locale-aware.
 *
 * The original table (007) keyed references on (source_entry_uuid, source_field, target_entry_uuid)
 * with no locale, so publishing/unpublishing one locale of a multi-locale entry wiped the other
 * locales' reference rows — breaking "what links here" and asset delete-protection (an asset still
 * referenced by the published `en` version was reported unused and became wrongly deletable).
 *
 * Adds a `locale` column and moves the unique key to include it. Existing rows carry no recoverable
 * locale (a projection row never recorded which locale's draft produced it), so — this being a
 * pre-production schema — the table is recreated empty and the projection repopulates on the next
 * publish/draft-save/backfill (ReferenceProjectionRepository::rebuildForEntry). Recreating rather
 * than ALTER-ing also sidesteps dropping the inline unique index from 007.
 */
final class AddLocaleToEntryReferences implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('entry_references');
        $schema->createTable('entry_references', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('source_entry_uuid', 12);
            $table->string('source_field', 160);
            $table->string('target_entry_uuid', 12);
            $table->string('locale', 16);
            $table->unique(
                ['source_entry_uuid', 'source_field', 'target_entry_uuid', 'locale'],
                'uniq_reference_source_field_target_locale'
            );
            $table->index('target_entry_uuid');  // reverse lookups ("what links here"), across locales
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        // Revert to the pre-locale shape (also empty — the projection repopulates on write).
        $schema->dropTableIfExists('entry_references');
        $schema->createTable('entry_references', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('source_entry_uuid', 12);
            $table->string('source_field', 160);
            $table->string('target_entry_uuid', 12);
            $table->unique(
                ['source_entry_uuid', 'source_field', 'target_entry_uuid'],
                'uniq_reference_source_field_target'
            );
            $table->index('target_entry_uuid');
        });
    }

    public function getDescription(): string
    {
        return 'Add locale to entry_references (locale-aware reference projection); recreated empty.';
    }
}

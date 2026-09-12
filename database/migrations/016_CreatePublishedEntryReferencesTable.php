<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * The PUBLISHED-reference projection (term-archives/facets spec §1) — one row per
 * (source entry, locale, field, target) where the SOURCE side is published. Maintained
 * by ProjectPublishedReferencesListener on publish/unpublish/delete; re-driven by
 * `thallo:resync`. Distinct from entry_references (draft-based admin reverse index).
 *
 * Target liveness is NOT tracked here — facet/archive queries join the target's
 * publication at read time, because a term can be unpublished without being deleted.
 */
final class CreatePublishedEntryReferencesTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        $schema->createTable('published_entry_references', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('source_entry_uuid', 12);
            $table->string('source_content_type_uuid', 12);
            $table->string('field', 160);
            $table->string('target_entry_uuid', 12);
            $table->string('locale', 16);
            $table->unique(
                ['source_entry_uuid', 'locale', 'field', 'target_entry_uuid'],
                'uniq_pubref_source_locale_field_target'
            );
            // Facet aggregation + archive membership probe.
            $table->index(
                ['source_content_type_uuid', 'field', 'locale', 'target_entry_uuid'],
                'idx_pubref_type_field_locale_target'
            );
            $table->index('target_entry_uuid');  // hygiene deletes on term delete
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('published_entry_references');
    }

    public function getDescription(): string
    {
        return 'Create published_entry_references (published-reference projection for term archives/facets).';
    }
}

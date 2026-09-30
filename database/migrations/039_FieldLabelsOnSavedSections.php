<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * A section saved from a layout keeps the labels of the fields it shows (sections and templates
 * design §3.2): offered in a layout whose type lacks one of them, it names that field by the label it
 * had where it was saved. Existing rows have none.
 */
final class FieldLabelsOnSavedSections implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('saved_sections') || $schema->hasColumn('saved_sections', 'field_labels')) {
            return;
        }
        $schema->alterTable('saved_sections', function ($t): void {
            $t->json('field_labels')->nullable();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('saved_sections') || !$schema->hasColumn('saved_sections', 'field_labels')) {
            return;
        }
        $schema->alterTable('saved_sections', function ($t): void {
            $t->dropColumn('field_labels');
        });
    }

    public function getDescription(): string
    {
        return 'Saved layout sections keep the labels of the fields they show.';
    }
}

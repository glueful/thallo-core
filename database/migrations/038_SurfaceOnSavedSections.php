<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * A section saved from a layout belongs to that kind of layout (sections and templates design §5):
 * its surface — `entry`, `listing`, `product`… — is kept beside its scope `layout`. Existing rows,
 * saved from pages and regions, have none and are untouched.
 */
final class SurfaceOnSavedSections implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('saved_sections') || $schema->hasColumn('saved_sections', 'surface')) {
            return;
        }
        $schema->alterTable('saved_sections', function ($t): void {
            $t->string('surface', 40)->nullable();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('saved_sections') || !$schema->hasColumn('saved_sections', 'surface')) {
            return;
        }
        $schema->alterTable('saved_sections', function ($t): void {
            $t->dropColumn('surface');
        });
    }

    public function getDescription(): string
    {
        return 'Saved sections remember the layout surface they belong to.';
    }
}

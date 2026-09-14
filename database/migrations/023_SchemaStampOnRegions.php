<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Visual builder (spec §7.3): a region is a block-bearing document and carries the same
 * schema stamp an entry carries in `fields._schema` — the settings schema version and the
 * conversion stages it completed — so the converter never re-runs a stage on it.
 */
final class SchemaStampOnRegions implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('regions') || $schema->hasColumn('regions', 'schema_stamp')) {
            return;
        }
        $schema->alterTable('regions', function ($table): void {
            $table->json('schema_stamp')->nullable();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('regions') || !$schema->hasColumn('regions', 'schema_stamp')) {
            return;
        }
        $schema->alterTable('regions', function ($table): void {
            $table->dropColumn('schema_stamp');
        });
    }

    public function getDescription(): string
    {
        return 'Regions carry the settings schema stamp';
    }
}

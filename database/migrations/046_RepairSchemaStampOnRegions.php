<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Puts back `regions.schema_stamp` (migration 023) where a multi-store retrofit dropped it: the
 * tenancy table rebuild once copied every regions column but that one, and 023 stays recorded as
 * run. Every walker over region blocks reads the column. A no-op where it is present.
 */
final class RepairSchemaStampOnRegions implements MigrationInterface
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
        // Nothing: the column belongs to migration 023, which removes it on its own rollback.
    }

    public function getDescription(): string
    {
        return 'Regions carry the settings schema stamp again after a multi-store retrofit';
    }
}

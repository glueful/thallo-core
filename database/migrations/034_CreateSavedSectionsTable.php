<?php

declare(strict_types=1);

use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Saved sections: a block an editor saves from the stage, offered in the Blocks tab's library
 * beside the shipped sections and inserted as a copy. The block is stored without ids — every
 * insert mints its own. A section belongs where it was saved from — a page body, or the header or
 * footer — and is offered there only. Owned by the tenancy pack (ThalloTenantTables): the column is here from
 * the start, and on an install already widened it is required, as the retrofit would leave it.
 */
final class CreateSavedSectionsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('saved_sections')) {
            return;
        }
        $schema->createTable('saved_sections', function ($table): void {
            $table->string('id', 12)->primary();
            $table->string('tenant_uuid', 12)->nullable();
            $table->string('name', 120);
            $table->string('category', 60);
            $table->text('description')->nullable();
            // `page`, or `region` with the region's slug: where the library offers it.
            $table->string('scope', 10)->default('page');
            $table->string('region', 20)->nullable();
            // One block tree, ids stripped: the same shape a shipped section hands out.
            $table->json('block');
            $table->string('created_by', 12)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index('tenant_uuid');
        });

        if (!$schema->hasTable('thallo_system_flags')) {
            return;
        }
        $db = new Connection();
        $state = $db->table('thallo_system_flags')->where('key', '=', 'tenancy.schema_state')->first();
        if (($state['value'] ?? null) === 'widened' && $db->getDriverName() === 'pgsql') {
            $db->getPDO()->exec('ALTER TABLE saved_sections ALTER COLUMN tenant_uuid SET NOT NULL');
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('saved_sections');
    }

    public function getDescription(): string
    {
        return 'Create saved_sections (blocks saved from the stage, reused from the Blocks tab).';
    }
}

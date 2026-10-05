<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Database\Schema\Builders\SchemaBuilder;

/**
 * The workspace font library (block typeface spec §2.3–§2.6): uploaded families and their faces. A
 * family's ID is what blocks, style classes and the Appearance assignments store, so it never changes;
 * `removed_at` is the soft delete Restore undoes. A face is one media-library `.woff2` file with the
 * weight range and style read from it; `unknown` marks a file the reader could not parse (kept by the
 * upgrade with the compatibility declaration, §2.5).
 *
 * One face per file per family: a unique index on `(COALESCE(tenant_uuid, ''), family_id, blob_uuid)`,
 * so a null tenant (a single-site install before tenancy widening) is one owner, not many. Both tables
 * are owned by the tenancy pack (ThalloTenantTables); on an install already widened the column is
 * required. The library's generation lives in the workspace's `settings` (`thallo.fonts.generation`).
 */
final class CreateFontLibraryTables implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('font_families')) {
            $schema->createTable('font_families', function ($table): void {
                $table->string('id', 12)->primary();
                $table->string('tenant_uuid', 12)->nullable();
                $table->string('name', 120);
                // One of sans-serif, serif, monospace, cursive, system-ui (FontStacks::FALLBACKS).
                $table->string('fallback', 16);
                $table->timestamp('removed_at')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->index('tenant_uuid');
            });
        }
        if (!$schema->hasTable('font_faces')) {
            $schema->createTable('font_faces', function ($table): void {
                $table->string('id', 12)->primary();
                $table->string('tenant_uuid', 12)->nullable();
                $table->string('family_id', 12);
                $table->string('blob_uuid', 12);
                $table->integer('weight_min');
                $table->integer('weight_max');
                $table->boolean('italic');
                $table->boolean('variable');
                $table->boolean('unknown')->default(false);
                $table->timestamp('created_at')->nullable();
                $table->index('tenant_uuid');
                $table->index('family_id');
                $table->index('blob_uuid');
            });

            if (!$schema instanceof SchemaBuilder) {
                throw new \RuntimeException('The font library migration requires the Glueful SchemaBuilder.');
            }
            // On the migration's own connection: the table exists only inside its transaction so far.
            $schema->getConnection()->getPDO()->exec(
                <<<'SQL'
                CREATE UNIQUE INDEX uniq_font_faces_blob
                  ON font_faces ((COALESCE(tenant_uuid, '')), family_id, blob_uuid)
                SQL
            );
        }

        if (!$schema instanceof SchemaBuilder || !$schema->hasTable('thallo_system_flags')) {
            return;
        }
        $pdo = $schema->getConnection()->getPDO();
        $state = $pdo->query("SELECT value FROM thallo_system_flags WHERE key = 'tenancy.schema_state'")
            ->fetchColumn();
        if ($state === 'widened') {
            $pdo->exec('ALTER TABLE font_families ALTER COLUMN tenant_uuid SET NOT NULL');
            $pdo->exec('ALTER TABLE font_faces ALTER COLUMN tenant_uuid SET NOT NULL');
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('font_faces');
        $schema->dropTableIfExists('font_families');
    }

    public function getDescription(): string
    {
        return 'Create the font library: uploaded families (stable IDs, soft delete) and their faces.';
    }
}

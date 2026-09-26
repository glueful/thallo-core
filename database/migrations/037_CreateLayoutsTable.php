<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Database\Schema\Builders\SchemaBuilder;

/**
 * Type layouts (type layouts spec §5.1): one block document per page kind — for Release A, per
 * content type — designed on the stage and rendered around every entry of that type. `blocks`
 * null is a tombstone: a removed layout keeps its row, so its `lock_version` never goes backwards
 * and an editor holding an old version cannot write over a layout removed and made again.
 *
 * One row per subject: a unique index on `(COALESCE(tenant_uuid, ''), surface, target)`, so a
 * null tenant (a single-site install before tenancy widening) is one subject, not many. Owned by
 * the tenancy pack (ThalloTenantTables); on an install already widened the column is required.
 */
final class CreateLayoutsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('layouts')) {
            return;
        }
        $schema->createTable('layouts', function ($table): void {
            $table->string('id', 12)->primary();
            $table->string('tenant_uuid', 12)->nullable();
            // `entry` (Release A), `listing`, `archive`, `product`, `shop_index`, `shop_category`.
            $table->string('surface', 40);
            // Never null: a type slug, `{type}:{field}`, or `@site`.
            $table->string('target', 160);
            $table->json('blocks')->nullable();
            // The frame's options: width, header, footer.
            $table->json('settings');
            $table->integer('lock_version')->default(0);
            $table->string('updated_by', 12)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index('tenant_uuid');
        });

        if (!$schema instanceof SchemaBuilder) {
            throw new \RuntimeException('The layouts migration requires the Glueful SchemaBuilder.');
        }
        // On the migration's own connection: the table exists only inside its transaction so far.
        $pdo = $schema->getConnection()->getPDO();
        $pdo->exec(
            <<<'SQL'
            CREATE UNIQUE INDEX uniq_layouts_subject
              ON layouts ((COALESCE(tenant_uuid, '')), surface, target)
            SQL
        );

        if (!$schema->hasTable('thallo_system_flags')) {
            return;
        }
        $state = $pdo->query("SELECT value FROM thallo_system_flags WHERE key = 'tenancy.schema_state'")
            ->fetchColumn();
        if ($state === 'widened') {
            $pdo->exec('ALTER TABLE layouts ALTER COLUMN tenant_uuid SET NOT NULL');
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('layouts');
    }

    public function getDescription(): string
    {
        return 'Create layouts (type layouts designed on the stage), with tombstones and one row per subject.';
    }
}

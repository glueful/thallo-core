<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Builders\SchemaBuilder;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateEntrySchemaMigrationsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('entry_schema_migrations')) {
            return;
        }

        $schema->createTable('entry_schema_migrations', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12)->unique();
            $table->string('content_type_uuid', 12);
            $table->integer('from_version');
            $table->integer('to_version');
            $table->json('ops');
            $table->string('status', 16)->default('pending');
            $table->integer('work_items_total')->default(0);
            $table->integer('work_items_done')->default(0);
            $table->integer('work_items_failed')->default(0);
            $table->json('failure_report')->nullable();
            $table->string('created_by', 12)->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->index(['content_type_uuid', 'from_version'], 'idx_schema_migrations_type_from');
        });

        if (!$schema instanceof SchemaBuilder) {
            throw new \RuntimeException('entry_schema_migrations migration requires the Glueful SchemaBuilder.');
        }

        $schema->getConnection()->getPDO()->exec(
            <<<'SQL'
            ALTER TABLE entry_schema_migrations
              ADD CONSTRAINT chk_entry_schema_migration_status
              CHECK (status IN ('pending', 'running', 'completed', 'failed'))
            SQL
        );

        $schema->getConnection()->getPDO()->exec(
            <<<'SQL'
            CREATE UNIQUE INDEX uniq_entry_schema_migrations_active
              ON entry_schema_migrations (content_type_uuid)
              WHERE status IN ('pending', 'running')
            SQL
        );
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('entry_schema_migrations');
    }

    public function getDescription(): string
    {
        return 'Create entry_schema_migrations for explicit content schema backfills.';
    }
}

<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateBlockTypeMigrationsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('block_type_migrations')) {
            return;
        }
        $schema->createTable('block_type_migrations', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('block_type_uuid', 12);
            // Declared op list: rename {from,to} | delete {name} — the content-type
            // migration vocabulary (block-migrations spec §2).
            $table->json('ops');
            // running | completed | failed. Running AND failed both keep the type's
            // write gate closed and block new declarations; only completed unlocks.
            $table->string('status', 16)->default('running');
            $table->integer('work_items_total')->default(0);
            $table->integer('work_items_done')->default(0);
            $table->integer('work_items_failed')->default(0);
            $table->json('failure_report');
            $table->string('created_by', 12)->nullable();
            // NO version numbers here: with no per-instance schema stamps, the
            // MICROSECOND created_at ordering IS the chain identity — restore
            // applies the completed suffix strictly after a version's created_at.
            $table->timestamp('created_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unique('uuid');
            $table->index('block_type_uuid', 'idx_block_type_migrations_type');
            $table->index('status', 'idx_block_type_migrations_status');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('block_type_migrations');
    }

    public function getDescription(): string
    {
        return 'Create block_type_migrations (eager block-schema migrations; '
            . 'microsecond created_at is the chain identity).';
    }
}

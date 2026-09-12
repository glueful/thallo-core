<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Builders\SchemaBuilder;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateEntrySchedulesTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('entry_schedules')) {
            return;
        }

        $schema->createTable('entry_schedules', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12)->unique();
            $table->string('entry_uuid', 12);
            $table->string('locale', 16);
            $table->string('action', 16);
            $table->timestamp('run_at');
            $table->string('status', 16)->default('pending');
            $table->integer('attempts')->default(0);
            $table->text('failure_reason')->nullable();
            $table->string('created_by', 12)->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->string('canceled_by', 12)->nullable();
            $table->index(['status', 'run_at'], 'idx_schedules_status_run_at');
        });

        if (!$schema instanceof SchemaBuilder) {
            throw new \RuntimeException('entry_schedules migration requires the Glueful SchemaBuilder.');
        }

        $pdo = $schema->getConnection()->getPDO();
        $pdo->exec(
            <<<'SQL'
            ALTER TABLE entry_schedules
              ADD CONSTRAINT chk_schedule_action
              CHECK (action IN ('publish', 'unpublish')),
              ADD CONSTRAINT chk_schedule_status
              CHECK (status IN ('pending', 'processing', 'done', 'failed', 'canceled'))
            SQL
        );
        $pdo->exec(
            <<<'SQL'
            CREATE UNIQUE INDEX uniq_pending_schedule
              ON entry_schedules (entry_uuid, locale, action)
              WHERE status = 'pending'
            SQL
        );
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('entry_schedules');
    }

    public function getDescription(): string
    {
        return 'Create entry_schedules for deferred publish and unpublish actions.';
    }
}

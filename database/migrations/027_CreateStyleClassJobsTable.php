<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Visual builder (spec §4.5): the record of a detach-everywhere or remove-everywhere job —
 * idempotent, pass-based, pinned to the class version it was queued against, holding the
 * class locked until it completes. The tenant column is retrofitted by the tenancy pack.
 */
final class CreateStyleClassJobsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('style_class_jobs')) {
            return;
        }
        $schema->createTable('style_class_jobs', function ($table): void {
            $table->string('id', 12)->primary();
            $table->string('class_id', 12);
            // The class version the job was queued against: a class edited since refuses to run.
            $table->integer('class_version');
            // detach | remove
            $table->string('kind', 8);
            // running | completed | failed
            $table->string('status', 16)->default('running');
            $table->integer('passes')->default(0);
            $table->integer('work_items_total')->default(0);
            $table->integer('work_items_done')->default(0);
            $table->integer('work_items_failed')->default(0);
            $table->json('failure_report');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->index('class_id', 'idx_style_class_jobs_class');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('style_class_jobs');
    }

    public function getDescription(): string
    {
        return 'Create style_class_jobs (detach-everywhere and remove-everywhere job records).';
    }
}

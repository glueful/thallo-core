<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Custom palette (spec §4.3): the per-workspace palette state row every palette mutation and every
 * fenced save takes first, and the replace jobs whose reservations it guards. The tenant column is
 * retrofitted by the tenancy pack, which widens the site unique to (tenant_uuid, site).
 */
final class CreatePaletteTables implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('palette_state')) {
            $schema->createTable('palette_state', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                // One row per site: the constant `site` under a unique index the tenancy pack widens.
                $table->string('site', 4)->default('site');
                $table->bigInteger('generation')->default(0);
                $table->timestamp('updated_at')->nullable();
                $table->unique('site', 'uniq_palette_state_site');
            });
        }
        if (!$schema->hasTable('palette_jobs')) {
            $schema->createTable('palette_jobs', function ($table): void {
                $table->string('id', 12)->primary();
                $table->integer('slot');
                $table->string('to_token', 64);
                $table->string('contrast_to_token', 64)->nullable(); // null: no contrast mapping (§4.2)
                $table->string('status', 16);                        // running | failed | completed | cancelled
                $table->integer('passes')->default(0);
                $table->integer('work_items_total')->default(0);
                $table->integer('work_items_done')->default(0);
                $table->integer('work_items_failed')->default(0);
                $table->json('failure_report')->nullable();
                $table->json('counts')->nullable();                  // per source, for the audit entry
                $table->string('workspace', 12)->nullable();         // the queued worker enters it
                $table->string('created_by', 12)->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamp('heartbeat_at')->nullable();       // a stale running job reads as interrupted
                $table->index('status');
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('palette_jobs');
        $schema->dropTableIfExists('palette_state');
    }

    public function getDescription(): string
    {
        return 'Create palette_state (the per-workspace palette lock and generation) and palette_jobs (replace jobs).';
    }
}

<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Docs sync from GitHub (docs sync design §4): the per-workspace state row whose conditional updates
 * fence every change to a run, the runs, and each run's pages. The tenant column is retrofitted by
 * the tenancy pack, which widens the state row's site unique to (tenant_uuid, site).
 */
final class CreateDocsSyncTables implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('docs_sync_state')) {
            $schema->createTable('docs_sync_state', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                // One row per site: the constant `site` under a unique index the tenancy pack widens.
                $table->string('site', 4)->default('site');
                $table->json('source')->nullable();
                $table->integer('revision')->default(0);
                $table->string('active_run', 12)->nullable();
                $table->string('lease_token', 64)->nullable();
                $table->timestamp('lease_at')->nullable();
                $table->string('synced_run', 12)->nullable();
                // Written by every fence with a fresh value, so the row always changes and MySQL
                // reports it affected even when the timestamps land in the same second.
                $table->string('touch', 32)->nullable();
                $table->timestamp('updated_at')->nullable();
                $table->unique('site', 'uniq_docs_sync_state_site');
            });
        }
        if (!$schema->hasTable('docs_sync_runs')) {
            $schema->createTable('docs_sync_runs', function ($table): void {
                $table->string('id', 12)->primary();
                $table->integer('revision');
                $table->json('source');
                $table->string('mode', 8);                     // commit | dry_run
                $table->string('ref', 255)->nullable();         // the tag or branch followed
                $table->string('sha', 40)->nullable();          // the commit it resolved to
                $table->string('status', 12);                   // reserved | dispatched | running | finished
                $table->string('outcome', 12)->nullable();
                foreach (['created', 'updated', 'unchanged', 'skipped', 'failed', 'gone'] as $counter) {
                    $table->integer($counter)->default(0);
                }
                $table->integer('attempts')->default(0);
                $table->boolean('cancel_requested')->default(false);
                $table->text('message')->nullable();
                $table->string('archive_path', 255)->nullable();
                $table->string('workspace', 12)->nullable();
                $table->string('created_by', 12)->nullable();
                $table->timestamp('created_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamp('last_dispatch_at')->nullable();
                $table->index('status');
            });
        }
        if (!$schema->hasTable('docs_sync_run_pages')) {
            $schema->createTable('docs_sync_run_pages', function ($table): void {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('run_id', 12);
                $table->string('path', 500);
                $table->string('slug', 255)->nullable();
                $table->string('status', 12);
                $table->text('message')->nullable();
                $table->json('broken_links')->nullable();
                $table->string('entry_uuid', 12)->nullable();
                $table->unique(['run_id', 'path'], 'uniq_docs_sync_run_page');
                $table->index('run_id');
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('docs_sync_run_pages');
        $schema->dropTableIfExists('docs_sync_runs');
        $schema->dropTableIfExists('docs_sync_state');
    }

    public function getDescription(): string
    {
        return 'Create docs_sync_state (the per-workspace docs source and run slot), docs_sync_runs and '
            . 'docs_sync_run_pages.';
    }
}

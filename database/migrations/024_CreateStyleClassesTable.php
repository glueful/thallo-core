<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Visual builder (spec §4.1): style classes — site-owned, theme-independent records a block
 * composes through `settings.classes`. Ids are stable; names are unique case-insensitively
 * through `name_key`; `version` is the optimistic-concurrency token; `archived_at` keeps old
 * revisions restorable; `locked_by_job` and `reference_guard` serialise the everywhere-jobs
 * against newly authored references (§4.5). The tenant column is retrofitted by the tenancy pack.
 */
final class CreateStyleClassesTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('style_classes')) {
            return;
        }
        $schema->createTable('style_classes', function ($table): void {
            $table->string('id', 12)->primary();
            $table->string('name', 120);
            // Lower-cased, trimmed name: the case-insensitive uniqueness the spec requires,
            // widened to (tenant_uuid, name_key) by the tenancy pack.
            $table->string('name_key', 120);
            $table->text('description')->nullable();
            // The §1 schema, sparse breakpoints and resets included; no capabilities, no targets.
            $table->json('style');
            $table->integer('version')->default(1);
            $table->timestamp('archived_at')->nullable();
            $table->string('locked_by_job', 12)->nullable();
            // Lock token bumped by a document write that introduces a reference (a write on this
            // row, so it serialises against a job's lock acquisition). Never part of a snapshot.
            $table->integer('reference_guard')->default(0);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique('name_key', 'uniq_style_class_name_key');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('style_classes');
    }

    public function getDescription(): string
    {
        return 'Create style_classes (site-owned style classes a block composes through settings.classes).';
    }
}

<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateFormSubmissionsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('form_submissions')) {
            return;
        }
        $schema->createTable('form_submissions', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            // sha256(source_identity | block_id) — groups submissions of the same form
            // across renders (form-block spec §5). NOT a foreign key: the form lives in
            // block data, not a table.
            $table->string('form_key', 64);
            $table->string('form_name', 255);
            $table->string('source_url', 1024)->nullable();
            // The sealed field list the visitor actually saw (the validation contract at
            // submit time), and the NORMALIZED values keyed by field (spec §6). `values`
            // is a SQL reserved word, hence submitted_values.
            $table->json('fields_snapshot');
            $table->json('submitted_values');
            $table->integer('descriptor_version')->default(1);
            // unread | read (triage state, admin spec §7).
            $table->string('status', 16)->default('unread');
            $table->string('ip', 64)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('submitted_at');
            $table->unique('uuid');
            $table->index('form_key', 'idx_form_submissions_form_key');
            $table->index('status', 'idx_form_submissions_status');
            $table->index('submitted_at', 'idx_form_submissions_submitted_at');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('form_submissions');
    }

    public function getDescription(): string
    {
        return 'Create form_submissions (stored contact-form submissions with normalized values).';
    }
}

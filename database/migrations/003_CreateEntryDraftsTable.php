<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateEntryDraftsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('entry_drafts')) {
            return;
        }
        $schema->createTable('entry_drafts', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('entry_uuid', 12);
            $table->string('locale', 16);
            $table->json('fields');
            $table->integer('schema_version');
            $table->integer('lock_version')->default(0);
            $table->string('updated_by', 12)->nullable();
            $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');
            // "one draft per (entry, locale)" — surrogate id + unique pair
            $table->unique(['entry_uuid', 'locale'], 'uniq_draft_entry_locale');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('entry_drafts');
    }

    public function getDescription(): string
    {
        return 'Create entry_drafts (single mutable working copy per entry+locale).';
    }
}

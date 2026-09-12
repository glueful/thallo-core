<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateEntryPublicationsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('entry_publications')) {
            return;
        }
        $schema->createTable('entry_publications', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('entry_uuid', 12);
            $table->string('locale', 16);
            $table->string('version_uuid', 12);       // -> entry_versions.uuid
            $table->string('published_by', 12)->nullable();
            $table->timestamp('published_at')->default('CURRENT_TIMESTAMP');
            $table->unique(['entry_uuid', 'locale'], 'uniq_publication_entry_locale');
            $table->index('version_uuid');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('entry_publications');
    }

    public function getDescription(): string
    {
        return 'Create entry_publications (the published-version pin, one per entry+locale).';
    }
}

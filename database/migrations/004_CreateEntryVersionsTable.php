<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateEntryVersionsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('entry_versions')) {
            return;
        }
        $schema->createTable('entry_versions', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('entry_uuid', 12);
            $table->string('locale', 16);
            $table->integer('version');
            $table->json('fields');
            $table->integer('schema_version');
            $table->string('created_by', 12)->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->unique('uuid');
            $table->unique(['entry_uuid', 'locale', 'version'], 'uniq_version_entry_locale_version');
            $table->index(['entry_uuid', 'locale']);
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('entry_versions');
    }

    public function getDescription(): string
    {
        return 'Create entry_versions (immutable append-only snapshots written at publish).';
    }
}

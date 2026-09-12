<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateEntryReferencesTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('entry_references')) {
            return;
        }
        $schema->createTable('entry_references', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('source_entry_uuid', 12);
            $table->string('source_field', 160);
            $table->string('target_entry_uuid', 12);
            $table->unique(
                ['source_entry_uuid', 'source_field', 'target_entry_uuid'],
                'uniq_reference_source_field_target'
            );
            $table->index('target_entry_uuid');  // reverse lookups ("what links here")
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('entry_references');
    }

    public function getDescription(): string
    {
        return 'Create entry_references (normalized reference index; projection deferred to delivery plan).';
    }
}

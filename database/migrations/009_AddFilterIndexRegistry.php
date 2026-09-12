<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class AddFilterIndexRegistry implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('filter_indexes')) {
            return;
        }
        $schema->createTable('filter_indexes', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('content_type_uuid', 12);
            $table->string('field', 160);
            $table->string('filter_type', 16);
            $table->string('index_name', 80);
            $table->string('status', 16)->default('pending');
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->unique('uuid');
            $table->unique(['content_type_uuid', 'field'], 'uniq_filter_index_type_field');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('filter_indexes');
    }

    public function getDescription(): string
    {
        return 'Track filterable-field expression indexes (name, type, field, status) for the delivery API.';
    }
}

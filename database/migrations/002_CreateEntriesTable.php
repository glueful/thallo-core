<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateEntriesTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('entries')) {
            return;
        }
        $schema->createTable('entries', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('content_type_uuid', 12);
            $table->enum('status', ['active', 'archived', 'deleted'], 'active');
            $table->string('created_by', 12)->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->nullable();
            $table->unique('uuid');
            $table->index('content_type_uuid');
            $table->index('status');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('entries');
    }

    public function getDescription(): string
    {
        return 'Create entries (locale-neutral identity spine).';
    }
}

<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateContentTypesTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('content_types')) {
            return;
        }
        $schema->createTable('content_types', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12);
            $table->string('slug', 160);
            $table->string('name', 200);
            $table->text('description')->nullable();
            $table->integer('cache_ttl')->nullable();
            $table->boolean('public_delivery')->default(false);
            // Root-mounted URL grammar: entries serve at /{locale?}/{slug}
            // (e.g. /about) instead of /{locale?}/{type}/{slug}.
            $table->boolean('mount_at_root')->default(false);
            $table->enum('status', ['active', 'archived', 'deleted'], 'active');
            $table->json('schema');                 // field definitions (JSONB)
            $table->integer('schema_version')->default(1);
            $table->string('created_by', 12)->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->nullable();
            $table->unique('uuid');
            $table->unique('slug');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('content_types');
    }

    public function getDescription(): string
    {
        return 'Create content_types (content models + JSONB field schema).';
    }
}

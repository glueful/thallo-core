<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateEntryRoutesTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('entry_routes')) {
            return;
        }
        $schema->createTable('entry_routes', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('entry_uuid', 12);
            $table->string('content_type_uuid', 12);  // denormalized so route lookups never join entries
            $table->string('locale', 16);
            $table->string('slug', 200);
            $table->unique(['content_type_uuid', 'locale', 'slug'], 'uniq_route_type_locale_slug');
            $table->index('entry_uuid');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('entry_routes');
    }

    public function getDescription(): string
    {
        return 'Create entry_routes (per content-type + locale slug uniqueness).';
    }
}

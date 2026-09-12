<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateSettingsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('settings')) {
            return;
        }

        $schema->createTable('settings', function ($table) {
            $table->string('key', 120)->primary();
            $table->text('value')->nullable();
            $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('settings');
    }

    public function getDescription(): string
    {
        return 'Create settings (key/value store for site settings and install state).';
    }
}

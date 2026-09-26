<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * The admin's menus and landing page, per role and per user (Users & Access): which sidebar items
 * someone sees and where signing in takes them. Tidying, not access — permissions decide what a
 * user may open. Global, like the users and roles it is keyed on.
 */
final class CreateAdminUiSettingsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('admin_ui_settings')) {
            return;
        }
        $schema->createTable('admin_ui_settings', function ($table): void {
            $table->string('id', 12)->primary();
            // `role` or `user`, and that role's or user's uuid.
            $table->string('subject_type', 10);
            $table->string('subject_uuid', 12);
            // Sidebar item path => `hidden` or `shown` (only a user's setting says `shown`).
            $table->json('menus');
            // The admin path signing in lands on; null for Home.
            $table->string('landing', 255)->nullable();
            $table->string('updated_by', 12)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['subject_type', 'subject_uuid'], 'uniq_admin_ui_settings_subject');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('admin_ui_settings');
    }

    public function getDescription(): string
    {
        return 'Create admin_ui_settings (sidebar menus and landing page per role and per user).';
    }
}

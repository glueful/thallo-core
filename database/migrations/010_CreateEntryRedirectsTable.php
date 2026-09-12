<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Builders\SchemaBuilder;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateEntryRedirectsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('entry_redirects')) {
            return;
        }

        $schema->createTable('entry_redirects', function ($table) {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12)->unique();
            $table->string('content_type_uuid', 12);
            $table->string('locale', 16);
            $table->string('source_slug', 200);
            $table->string('target_content_type_uuid', 12)->nullable();
            $table->string('target_locale', 16)->nullable();
            $table->string('target_entry_uuid', 12)->nullable();
            $table->string('target_url', 2048)->nullable();
            $table->integer('status')->default(301);
            $table->string('origin', 16)->default('manual');
            $table->string('created_by', 12)->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->nullable();
            $table->unique(['content_type_uuid', 'locale', 'source_slug'], 'uniq_redirect_type_locale_source');
            $table->index(['target_entry_uuid'], 'idx_redirect_target_entry');
        });

        if (!$schema instanceof SchemaBuilder) {
            throw new \RuntimeException('entry_redirects migration requires the Glueful SchemaBuilder.');
        }

        $pdo = $schema->getConnection()->getPDO();
        $pdo->exec(
            <<<'SQL'
            ALTER TABLE entry_redirects
              ADD CONSTRAINT chk_entry_redirect_status
              CHECK (status IN (301, 302, 308)),
              ADD CONSTRAINT chk_entry_redirect_origin
              CHECK (origin IN ('auto', 'manual')),
              ADD CONSTRAINT chk_entry_redirect_exactly_one_target
              CHECK (
                (
                  target_entry_uuid IS NOT NULL
                  AND target_content_type_uuid IS NOT NULL
                  AND target_locale IS NOT NULL
                  AND target_url IS NULL
                )
                OR
                (
                  target_entry_uuid IS NULL
                  AND target_content_type_uuid IS NULL
                  AND target_locale IS NULL
                  AND target_url IS NOT NULL
                )
              )
            SQL
        );
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('entry_redirects');
    }

    public function getDescription(): string
    {
        return 'Create entry_redirects for entry-targeted and literal SEO redirects.';
    }
}

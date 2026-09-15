<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Visual builder (spec §4.3): the site style generation — one row per site, the version of the
 * site's style-class definitions. Incremented atomically inside every class write's transaction
 * and by nothing else. The tenant column is retrofitted by the tenancy pack, which scopes the
 * row per site.
 */
final class CreateStyleGenerationsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('style_generations')) {
            return;
        }
        $schema->createTable('style_generations', function ($table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
            // One row per site: the constant `site` under a unique index the tenancy pack widens
            // to (tenant_uuid, site), so two concurrent first writers cannot each create a row.
            $table->string('site', 4)->default('site');
            $table->integer('generation')->default(0);
            $table->timestamp('updated_at')->nullable();
            $table->unique('site', 'uniq_style_generation_site');
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('style_generations');
    }

    public function getDescription(): string
    {
        return 'Create style_generations (the per-site version of the style-class definitions).';
    }
}

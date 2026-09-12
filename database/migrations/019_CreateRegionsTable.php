<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

final class CreateRegionsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('regions')) {
            return;
        }
        $schema->createTable('regions', function ($table) {
            // Slug-keyed chrome regions (global-regions spec): 'header', 'footer' in v1.
            // Deliberately no locale column (global in v1) and no draft state (saves
            // apply immediately) — both are additive later.
            $table->string('slug', 64)->primary();
            // Ordered {id,type,data} list — the entry blocks-field shape.
            $table->json('blocks');
            // Fixed per-region vocabulary (RegionDefinitions::SETTINGS_KEYS).
            $table->json('settings');
            $table->timestamp('updated_at')->nullable();
            $table->string('updated_by', 12)->nullable();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('regions');
    }

    public function getDescription(): string
    {
        return 'Create regions (global header/footer block regions).';
    }
}

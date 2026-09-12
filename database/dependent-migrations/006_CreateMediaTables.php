<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Thallo media-library sidecars over the framework-owned `blobs` table.
 *
 *  - media_meta:  per-blob CMS metadata (alt text, caption, tags) that Thallo owns WITHOUT touching
 *                 the framework `blobs` schema. One row per blob, created on first metadata edit.
 *  - media_usage: a reverse index — "which entries reference this blob" — maintained by a listener
 *                 on the content AssetAttached/AssetDetached events (plus a one-off backfill).
 *
 * Soft references only (blob_uuid / entry_uuid); no cross-package foreign keys, matching the audit
 * table's stance — the referenced rows live in framework / content schema owned elsewhere.
 */
final class CreateMediaTables implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('media_meta')) {
            $schema->createTable('media_meta', function ($table) {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('blob_uuid', 12);
                $table->string('tenant_uuid', 12)->nullable();
                $table->text('alt_text')->nullable();
                $table->text('caption')->nullable();
                $table->json('tags')->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->nullable();

                $table->unique('blob_uuid');
                $table->index('tenant_uuid');
            });
        }

        if (!$schema->hasTable('media_usage')) {
            $schema->createTable('media_usage', function ($table) {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('blob_uuid', 12);
                $table->string('entry_uuid', 64);
                $table->string('tenant_uuid', 12)->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

                $table->unique(['blob_uuid', 'entry_uuid'], 'uniq_media_usage');
                $table->index('blob_uuid');
                $table->index('entry_uuid');
                $table->index('tenant_uuid');
            });
        }

        if (!$schema->hasTable('media_assets')) {
            $schema->createTable('media_assets', function ($table) {
                $table->bigInteger('id')->primary()->autoIncrement();
                $table->string('blob_uuid', 12);
                $table->string('tenant_uuid', 12)->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

                $table->unique('blob_uuid');
                $table->index('tenant_uuid');
                $table->foreign('blob_uuid')->references('uuid')->on('blobs')->cascadeOnDelete();
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('media_assets');
        $schema->dropTableIfExists('media_usage');
        $schema->dropTableIfExists('media_meta');
    }

    public function getDescription(): string
    {
        return 'Create media_meta (alt/caption/tags) and media_usage (blob→entry index) sidecars.';
    }
}

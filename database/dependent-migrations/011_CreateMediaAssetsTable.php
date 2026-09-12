<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/** Adds the blob-to-tenant ownership ledger to databases where migration 006 already ran. */
final class CreateMediaAssetsTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('media_assets')) {
            return;
        }

        $schema->createTable('media_assets', function ($table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('blob_uuid', 12);
            $table->string('tenant_uuid', 12)->nullable();
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

            $table->unique('blob_uuid');
            $table->index('tenant_uuid');
            $table->foreign('blob_uuid')->references('uuid')->on('blobs')->cascadeOnDelete();
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('media_assets');
    }

    public function getDescription(): string
    {
        return 'Create the one-owner media_assets tenant ledger.';
    }
}

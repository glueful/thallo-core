<?php

declare(strict_types=1);

use Thallo\Core\Content\Blocks\StarterFieldsAppender;
use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Builders\SchemaBuilder;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/** Add marker_size to the `feature` block type on an existing install (see StarterFieldsAppender). */
final class MarkerSizeOnFeatureBlockType implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('block_types')) {
            return;
        }
        if (!$schema instanceof SchemaBuilder) {
            throw new \RuntimeException('feature marker size migration requires the Glueful SchemaBuilder.');
        }
        (new StarterFieldsAppender($schema->getConnection()))->append('feature', ['marker_size']);
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        // Additive only: removing a field orphans stored instance keys (block-migrations spec §1).
    }

    public function getDescription(): string
    {
        return 'Add marker_size to the feature block type.';
    }
}

<?php

declare(strict_types=1);

use Thallo\Core\Content\Blocks\StarterFieldsAppender;
use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Builders\SchemaBuilder;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Add marker, number, marker colours, variant and orientation to the `feature` block type on an
 * existing install
 * (additive, label-preserving, once — see StarterFieldsAppender).
 */
final class CardFieldsOnFeatureBlockType implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('block_types')) {
            return;
        }
        if (!$schema instanceof SchemaBuilder) {
            throw new \RuntimeException('feature card fields migration requires the Glueful SchemaBuilder.');
        }
        (new StarterFieldsAppender($schema->getConnection()))
            ->append('feature', ['marker', 'number', 'marker_background', 'marker_color', 'variant', 'orientation']);
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        // Additive only: removing a field orphans stored instance keys (block-migrations spec §1).
    }

    public function getDescription(): string
    {
        return 'Add marker, number, marker colours, variant and orientation to the feature block type.';
    }
}

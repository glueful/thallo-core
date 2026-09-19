<?php

declare(strict_types=1);

use Thallo\Core\Content\Blocks\StarterFieldsAppender;
use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Builders\SchemaBuilder;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/** Add the gradient's colour and strength to the `hero` block type on an existing install. */
final class GradientFieldsOnHeroBlockType implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('block_types')) {
            return;
        }
        if (!$schema instanceof SchemaBuilder) {
            throw new \RuntimeException('hero gradient fields migration requires the Glueful SchemaBuilder.');
        }
        (new StarterFieldsAppender($schema->getConnection()))
            ->append('hero', ['gradient_color', 'gradient_strength']);
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        // Additive only: removing a field orphans stored instance keys (block-migrations spec §1).
    }

    public function getDescription(): string
    {
        return 'Add gradient_color and gradient_strength to the hero block type.';
    }
}

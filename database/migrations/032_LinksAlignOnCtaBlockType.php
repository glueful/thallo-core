<?php

declare(strict_types=1);

use Thallo\Core\Content\Blocks\StarterFieldsAppender;
use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Builders\SchemaBuilder;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/** Add links_align to the `cta` block type on an existing install (see StarterFieldsAppender). */
final class LinksAlignOnCtaBlockType implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('block_types')) {
            return;
        }
        if (!$schema instanceof SchemaBuilder) {
            throw new \RuntimeException('cta links_align migration requires the Glueful SchemaBuilder.');
        }
        (new StarterFieldsAppender($schema->getConnection()))->append('cta', ['links_align']);
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        // Additive only: removing a field orphans stored instance keys (block-migrations spec §1).
    }

    public function getDescription(): string
    {
        return 'Add links_align to the call-to-action block type.';
    }
}

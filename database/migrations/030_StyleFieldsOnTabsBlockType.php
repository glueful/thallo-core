<?php

declare(strict_types=1);

use Thallo\Core\Content\Blocks\StarterFieldsAppender;
use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Builders\SchemaBuilder;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Add the tabs block's strip and panel fields (variant, align, colours, panel padding) to an
 * existing install, and — on a row still carrying the starter's previous style declaration
 * (spacing, visibility) — adopt the new one, which sends colours, radius, border and shadow to
 * the panels target. Additive, label-preserving, once (see StarterFieldsAppender).
 */
final class StyleFieldsOnTabsBlockType implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('block_types')) {
            return;
        }
        if (!$schema instanceof SchemaBuilder) {
            throw new \RuntimeException('tabs style fields migration requires the Glueful SchemaBuilder.');
        }
        $appender = new StarterFieldsAppender($schema->getConnection());
        $appender->append('tabs', [
            'variant', 'align', 'list_background', 'tab_color', 'active_background', 'active_color', 'panel_padding',
        ]);
        $appender->adoptStarterStyle('tabs', ['spacing', 'visibility']);
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        // Additive only: removing a field orphans stored instance keys (block-migrations spec §1).
    }

    public function getDescription(): string
    {
        return 'Add the tabs block\'s strip and panel fields; adopt the panels style target.';
    }
}

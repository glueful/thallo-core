<?php

declare(strict_types=1);

use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Blocks\StarterBlockTypes;
use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Builders\SchemaBuilder;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Re-seed the `navigation` block type with its v2 schema.
 *
 * The navigation block's config changed destructively (the old hover_style /
 * active_style enums were replaced by variant / color / highlight + submenu_layout).
 * `thallo:blocks:seed` is idempotent-by-slug and SKIPS an existing slug, so it can
 * never push the new shape onto a database that already has the old row — and the
 * change removes fields, so the additive updateSchema() guard would reject it too.
 *
 * So: delete the existing row and recreate it from StarterBlockTypes (the one source
 * of truth — no schema JSON duplicated here). Block INSTANCES keep their stored data;
 * any config key that no longer exists in the new schema is simply ignored at render.
 * On a fresh install this seeds navigation early; the later `blocks:seed` fills in the
 * rest and skips navigation because it now exists.
 */
final class ReseedNavigationBlockType implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('block_types')) {
            return;
        }
        if (!$schema instanceof SchemaBuilder) {
            throw new \RuntimeException('navigation reseed migration requires the Glueful SchemaBuilder.');
        }

        $conn = $schema->getConnection();
        $conn->getPDO()->exec("DELETE FROM block_types WHERE slug = 'navigation'");

        $repo = new BlockTypeRepository($conn);
        foreach (StarterBlockTypes::definitions() as $definition) {
            if ($definition['slug'] === 'navigation') {
                $repo->create($definition);
                break;
            }
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        if (!$schema instanceof SchemaBuilder) {
            return;
        }
        // Non-restorable (the old schema is gone); drop the row so a re-run of up()
        // recreates it cleanly.
        $schema->getConnection()->getPDO()->exec("DELETE FROM block_types WHERE slug = 'navigation'");
    }

    public function getDescription(): string
    {
        return 'Re-seed the navigation block type with the v2 variant/color/highlight schema.';
    }
}

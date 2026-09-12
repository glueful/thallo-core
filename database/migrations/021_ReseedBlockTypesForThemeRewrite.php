<?php

declare(strict_types=1);

use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Blocks\StarterBlockTypes;
use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Builders\SchemaBuilder;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Reseed block types for the default-theme rewrite.
 *
 * The theme was consolidated into a primitive block set: legacy blocks whose
 * templates were deleted are removed, the child-carrier item types are renamed to
 * the new primitives (faq_item → accordion_item, step → stepper_item), 9 new
 * parent blocks are added, and 4 surviving blocks (section, container, grid,
 * button) had
 * their SCHEMA drift-updated in place. `thallo:blocks:seed` skips existing slugs,
 * so it cannot push any of this onto a migrated database — this migration
 * reconciles the row set, reading StarterBlockTypes as the single source of truth
 * (no schema JSON duplicated here). Block INSTANCES keep their stored data; any
 * instance of a removed slug renders as a missing-template comment and drops from
 * the picker.
 */
final class ReseedBlockTypesForThemeRewrite implements MigrationInterface
{
    /** Legacy slugs whose definitions are gone from StarterBlockTypes. */
    private const REMOVED = [
        'divider', 'quote', 'features', 'testimonials', 'faq', 'steps',
        'gallery', 'logo_cloud', 'testimonial', 'faq_item', 'step',
    ];

    /** New primitive slugs to (re)seed from StarterBlockTypes. */
    private const ADDED = [
        'accordion', 'card', 'collapsible', 'color_mode', 'footer', 'footer_columns',
        'links', 'logos', 'separator', 'stepper', 'accordion_item', 'stepper_item',
    ];

    /** Surviving slugs whose schema drifted (fields/enums changed) — refreshed. */
    private const DRIFTED = [
        'section', 'container', 'grid', 'button',
    ];

    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('block_types')) {
            return;
        }
        if (!$schema instanceof SchemaBuilder) {
            throw new \RuntimeException('block-types reseed requires the Glueful SchemaBuilder.');
        }
        $conn = $schema->getConnection();
        $pdo = $conn->getPDO();

        // Delete removed + to-be-recreated slugs (idempotent; delete-then-create
        // always converges to one fresh row per new/drifted slug — the 020
        // precedent). DRIFTED rows are dropped then re-created so their updated
        // schema lands; instances (keyed by slug in entry JSON) are unaffected.
        $all = array_merge(self::REMOVED, self::ADDED, self::DRIFTED);
        $in = implode(',', array_fill(0, count($all), '?'));
        $pdo->prepare("DELETE FROM block_types WHERE slug IN ($in)")->execute($all);

        $recreate = array_merge(self::ADDED, self::DRIFTED);
        $repo = new BlockTypeRepository($conn);
        foreach (StarterBlockTypes::definitions() as $def) {
            if (in_array($def['slug'], $recreate, true)) {
                $repo->create($def);
            }
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        if (!$schema instanceof SchemaBuilder) {
            return;
        }
        // The new primitives can be dropped so up() re-runs cleanly; the 11 removed
        // slugs and the pre-drift schema of section/container/grid are NOT restorable
        // (their old StarterBlockTypes defs are gone) — one-way. DRIFTED rows keep
        // their refreshed schema.
        $in = implode(',', array_fill(0, count(self::ADDED), '?'));
        $schema->getConnection()->getPDO()
            ->prepare("DELETE FROM block_types WHERE slug IN ($in)")
            ->execute(self::ADDED);
    }

    public function getDescription(): string
    {
        return 'Reseed block types for the default-theme rewrite '
            . '(drop 11 legacy, add 12 primitives, refresh 4 drifted schemas).';
    }
}

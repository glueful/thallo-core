<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Visual builder (spec §1.7): a block type declares its style capabilities, its named style
 * targets, flags (`legacy_presentation`, `renders_children_inline`) and starter content. All
 * nullable: undeclared means none. Numbered 017 (after CreateBlockTypesTable, before every
 * migration that seeds block types through the repository), since the repository writes these
 * columns on every insert.
 */
final class StyleColumnsOnBlockTypes implements MigrationInterface
{
    private const COLUMNS = ['style_capabilities', 'style_targets', 'flags', 'starter_content'];

    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('block_types')) {
            return;
        }
        $missing = array_values(array_filter(
            self::COLUMNS,
            static fn (string $column): bool => !$schema->hasColumn('block_types', $column),
        ));
        if ($missing === []) {
            return;
        }
        $schema->alterTable('block_types', function ($table) use ($missing): void {
            foreach ($missing as $column) {
                $table->json($column)->nullable();
            }
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('block_types')) {
            return;
        }
        $present = array_values(array_filter(
            self::COLUMNS,
            static fn (string $column): bool => $schema->hasColumn('block_types', $column),
        ));
        if ($present === []) {
            return;
        }
        $schema->alterTable('block_types', function ($table) use ($present): void {
            foreach ($present as $column) {
                $table->dropColumn($column);
            }
        });
    }

    public function getDescription(): string
    {
        return 'Block types declare style capabilities, targets, flags and starter content';
    }
}

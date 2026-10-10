<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Custom palette (spec §5.3; plan Task 12): a completed replace job is a replacement record, ordered by
 * the palette generation it completed at, which editors apply to the history they hold; the palette
 * state's history horizon is the generation below which records may have been pruned.
 */
final class AddPaletteReplacementRecords implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('palette_jobs') && !$schema->hasColumn('palette_jobs', 'completed_generation')) {
            $schema->alterTable('palette_jobs', function ($t): void {
                $t->bigInteger('completed_generation')->nullable();
                $t->index('completed_generation');
            });
        }
        if ($schema->hasTable('palette_state') && !$schema->hasColumn('palette_state', 'history_horizon')) {
            $schema->alterTable('palette_state', function ($t): void {
                $t->bigInteger('history_horizon')->default(0);
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('palette_state') && $schema->hasColumn('palette_state', 'history_horizon')) {
            $schema->alterTable('palette_state', fn ($t) => $t->dropColumn('history_horizon'));
        }
        if ($schema->hasTable('palette_jobs') && $schema->hasColumn('palette_jobs', 'completed_generation')) {
            $schema->alterTable('palette_jobs', fn ($t) => $t->dropColumn('completed_generation'));
        }
    }

    public function getDescription(): string
    {
        return 'Palette replacement records: the generation a replace job completed at, and the history horizon.';
    }
}

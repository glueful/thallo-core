<?php

declare(strict_types=1);

use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Blocks\StarterBlockTypes;
use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Builders\SchemaBuilder;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Add the per-part alignment fields (headline_align, title_align, description_align) to the
 * `section` block type on an existing install.
 *
 * `thallo:blocks:seed` skips an existing slug, so a field added to StarterBlockTypes never
 * reaches a database that already has the row. The change is purely additive, so unlike the
 * 020/021 reseeds it goes through the repository's additive updateSchema(): the row keeps
 * whatever label, icon, description and category an admin gave it, and instances keep their
 * data. Idempotent — a section that already declares the fields is left alone. The new fields
 * are read from StarterBlockTypes (the one source of truth), never duplicated here.
 */
final class AlignmentFieldsOnSectionBlockType implements MigrationInterface
{
    private const SLUG = 'section';
    private const FIELDS = ['headline_align', 'title_align', 'description_align'];

    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('block_types')) {
            return;
        }
        if (!$schema instanceof SchemaBuilder) {
            throw new \RuntimeException('section alignment migration requires the Glueful SchemaBuilder.');
        }
        $repo = new BlockTypeRepository($schema->getConnection());
        $row = $repo->findBySlug(self::SLUG);
        if ($row === null) {
            return; // a fresh install: the seeder creates the row with the fields already in it
        }
        $current = (array) $row['schema'];
        $present = [];
        foreach ($current as $field) {
            $present[(string) ($field['name'] ?? '')] = true;
        }
        $additions = [];
        foreach ($this->starterFields() as $field) {
            if (!isset($present[$field['name']])) {
                $additions[] = $field;
            }
        }
        if ($additions === []) {
            return;
        }
        $repo->updateSchema(
            (string) $row['uuid'],
            array_merge($current, $additions),
            (string) $row['label'],
            isset($row['icon']) ? (string) $row['icon'] : null,
            isset($row['description']) ? (string) $row['description'] : null,
            isset($row['category']) ? (string) $row['category'] : null,
        );
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        // Additive only: removing a field orphans stored instance keys (block-migrations spec §1),
        // so the fields stay. Instances that never set them render the orientation default.
    }

    public function getDescription(): string
    {
        return 'Add headline_align, title_align and description_align to the section block type.';
    }

    /** @return list<array<string,mixed>> the alignment fields as StarterBlockTypes declares them */
    private function starterFields(): array
    {
        foreach (StarterBlockTypes::definitions() as $definition) {
            if ($definition['slug'] !== self::SLUG) {
                continue;
            }
            $out = [];
            foreach ($definition['schema'] as $field) {
                if (in_array($field['name'], self::FIELDS, true)) {
                    $out[] = $field;
                }
            }
            return $out;
        }
        return [];
    }
}

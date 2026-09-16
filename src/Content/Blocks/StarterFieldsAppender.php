<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Blocks;

use Glueful\Database\Connection;

/**
 * Append fields a starter block type gained after an install seeded it.
 *
 * `thallo:blocks:seed` skips an existing slug, so a field added to StarterBlockTypes never
 * reaches a database that already has the row. A purely additive change goes through the
 * repository's additive updateSchema(): the row keeps whatever label, icon, description and
 * category an admin gave it, instances keep their data, and a row that already declares the
 * fields is left alone. The fields are read from StarterBlockTypes — the one source of truth —
 * never duplicated by the migration that calls this.
 */
final class StarterFieldsAppender
{
    private readonly BlockTypeRepository $repo;

    public function __construct(Connection $connection)
    {
        $this->repo = new BlockTypeRepository($connection);
    }

    /**
     * @param list<string> $fieldNames the starter's fields to append, in the starter's order
     * @return int how many fields were appended (0: no row, or nothing missing)
     */
    public function append(string $slug, array $fieldNames): int
    {
        $row = $this->repo->findBySlug($slug);
        if ($row === null) {
            return 0; // a fresh install: the seeder creates the row with the fields already in it
        }
        $current = (array) $row['schema'];
        $present = [];
        foreach ($current as $field) {
            $present[(string) ($field['name'] ?? '')] = true;
        }
        $additions = [];
        foreach ($this->starterFields($slug, $fieldNames) as $field) {
            if (!isset($present[$field['name']])) {
                $additions[] = $field;
            }
        }
        if ($additions === []) {
            return 0;
        }
        $this->repo->updateSchema(
            (string) $row['uuid'],
            array_merge($current, $additions),
            (string) $row['label'],
            isset($row['icon']) ? (string) $row['icon'] : null,
            isset($row['description']) ? (string) $row['description'] : null,
            isset($row['category']) ? (string) $row['category'] : null,
        );
        return count($additions);
    }

    /**
     * @param list<string> $fieldNames
     * @return list<array<string,mixed>>
     */
    private function starterFields(string $slug, array $fieldNames): array
    {
        foreach (StarterBlockTypes::definitions() as $definition) {
            if ($definition['slug'] !== $slug) {
                continue;
            }
            $out = [];
            foreach ($definition['schema'] as $field) {
                if (in_array($field['name'], $fieldNames, true)) {
                    $out[] = $field;
                }
            }
            return $out;
        }
        return [];
    }
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Patterns;

use Glueful\Database\Connection;
use Glueful\Helpers\Utils;

/**
 * The site's saved sections (see {@see PatternLibrary}). Rows go through the query builder, so the
 * tenancy pack scopes them like every other owned table.
 */
final class SavedSectionRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return list<array{id: string, name: string, category: string, description: ?string, scope: string, region: ?string, block: array<string,mixed>}> */
    public function all(): array
    {
        $rows = $this->db->table('saved_sections')
            ->select(['id', 'name', 'category', 'description', 'scope', 'region', 'block'])
            ->orderBy('created_at', 'ASC')
            ->get();
        $out = [];
        foreach ($rows as $row) {
            $block = is_string($row['block'] ?? null) ? json_decode($row['block'], true) : ($row['block'] ?? null);
            if (!is_array($block)) {
                continue;
            }
            $out[] = [
                'id' => (string) $row['id'],
                'name' => (string) $row['name'],
                'category' => (string) $row['category'],
                'description' => isset($row['description']) ? (string) $row['description'] : null,
                'scope' => ($row['scope'] ?? 'page') === 'region' ? 'region' : 'page',
                'region' => isset($row['region']) ? (string) $row['region'] : null,
                'block' => $block,
            ];
        }
        return $out;
    }

    public function exists(string $id): bool
    {
        return $this->db->table('saved_sections')->where('id', $id)->first() !== null;
    }

    /**
     * @param array<string,mixed> $block the tree without ids
     * @param ?string $region the region's slug for a `region` section; null for a page's
     */
    public function create(
        string $name,
        string $category,
        ?string $description,
        array $block,
        ?string $region,
        ?string $by,
    ): string {
        $id = Utils::generateNanoID();
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('saved_sections')->insert([
            'id' => $id,
            'name' => $name,
            'category' => $category,
            'description' => $description,
            'scope' => $region === null ? 'page' : 'region',
            'region' => $region,
            'block' => json_encode($block, JSON_THROW_ON_ERROR),
            'created_by' => $by,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $id;
    }

    /** @param array{name?: string, category?: string, description?: ?string} $changes */
    public function update(string $id, array $changes): void
    {
        if ($changes === []) {
            return;
        }
        $this->db->table('saved_sections')->where('id', $id)->update(
            $changes + ['updated_at' => gmdate('Y-m-d H:i:s')],
        );
    }

    public function delete(string $id): bool
    {
        return $this->db->table('saved_sections')->where('id', $id)->delete() > 0;
    }
}

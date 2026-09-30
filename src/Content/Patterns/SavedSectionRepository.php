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

    /**
     * @return list<array{id: string, name: string, category: string, description: ?string, scope: string,
     *     region: ?string, surface: ?string, block: array<string,mixed>, lock_version: int}>
     */
    public function all(): array
    {
        $rows = $this->db->table('saved_sections')
            ->select(self::COLUMNS)
            ->orderBy('created_at', 'ASC')
            ->get();
        $out = [];
        foreach ($rows as $row) {
            $shaped = self::shape($row);
            if ($shaped !== null) {
                $out[] = $shaped;
            }
        }
        return $out;
    }

    /**
     * One saved section, shaped as {@see self::all()} shapes them; null when there is none.
     *
     * @return array{id: string, name: string, category: string, description: ?string, scope: string,
     *     region: ?string, surface: ?string, block: array<string,mixed>, lock_version: int}|null
     */
    public function find(string $id): ?array
    {
        $row = $this->db->table('saved_sections')->select(self::COLUMNS)->where('id', $id)->first();
        return $row === null ? null : self::shape((array) $row);
    }

    private const COLUMNS = [
        'id', 'name', 'category', 'description', 'scope', 'region', 'surface', 'block', 'lock_version',
    ];

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>|null null for a row whose block is unreadable
     */
    private static function shape(array $row): ?array
    {
        $block = is_string($row['block'] ?? null) ? json_decode($row['block'], true) : ($row['block'] ?? null);
        if (!is_array($block)) {
            return null;
        }
        $scope = (string) ($row['scope'] ?? 'page');
        return [
            'id' => (string) $row['id'],
            'name' => (string) $row['name'],
            'category' => (string) $row['category'],
            'description' => isset($row['description']) ? (string) $row['description'] : null,
            'scope' => in_array($scope, ['region', 'layout'], true) ? $scope : 'page',
            'region' => isset($row['region']) ? (string) $row['region'] : null,
            'surface' => isset($row['surface']) ? (string) $row['surface'] : null,
            'block' => $block,
            'lock_version' => (int) ($row['lock_version'] ?? 0),
        ];
    }

    public function exists(string $id): bool
    {
        return $this->db->table('saved_sections')->where('id', $id)->first() !== null;
    }

    /**
     * @param array<string,mixed> $block the tree without ids
     * @param ?string $region the region's slug for a `region` section; null otherwise
     * @param ?string $surface the layout surface for a `layout` section; null otherwise
     */
    public function create(
        string $name,
        string $category,
        ?string $description,
        array $block,
        ?string $region,
        ?string $by,
        ?string $surface = null,
    ): string {
        $id = Utils::generateNanoID();
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('saved_sections')->insert([
            'id' => $id,
            'name' => $name,
            'category' => $category,
            'description' => $description,
            'scope' => $surface !== null ? 'layout' : ($region === null ? 'page' : 'region'),
            'region' => $surface !== null ? null : $region,
            'surface' => $surface,
            'block' => json_encode($block, JSON_THROW_ON_ERROR),
            'created_by' => $by,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $id;
    }

    /**
     * Rename, recategorise or describe. Bumps the version, so a block walker holding the old one
     * is refused and re-reads.
     *
     * @param array{name?: string, category?: string, description?: ?string} $changes
     */
    public function update(string $id, array $changes): void
    {
        if ($changes === []) {
            return;
        }
        $row = $this->db->table('saved_sections')->select(['lock_version'])->where('id', $id)->first();
        if ($row === null) {
            return;
        }
        $this->db->table('saved_sections')->where('id', $id)->update($changes + [
            'lock_version' => (int) ($row['lock_version'] ?? 0) + 1,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Replace the block, only while the section is still at `$expected` — the version a block
     * walker read it at. False when it moved on: nothing written.
     *
     * @param array<string,mixed> $block
     */
    public function replaceBlock(string $id, int $expected, array $block): bool
    {
        return $this->db->table('saved_sections')
            ->where('id', $id)
            ->where('lock_version', '=', $expected)
            ->update([
                'block' => json_encode($block, JSON_THROW_ON_ERROR),
                'lock_version' => $expected + 1,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]) >= 1;
    }

    public function delete(string $id): bool
    {
        return $this->db->table('saved_sections')->where('id', $id)->delete() > 0;
    }
}

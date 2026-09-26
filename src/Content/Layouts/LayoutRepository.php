<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Layouts;

use Glueful\Database\Connection;
use Glueful\Helpers\Utils;

/**
 * The stored layouts (type layouts spec §5.1). Every write runs under the layout's write lock and
 * is conditional on the version the writer read: `expected` 0 creates the row only when none
 * exists (a missing row is version 0); otherwise the update matches only that version. Removal
 * keeps the row with `blocks` null — a tombstone — so the version keeps counting. Rows go through
 * the query builder, so the tenancy pack scopes them like every other owned table.
 */
/** Not final: the save contract's proofs substitute a repository that fails inside the write. */
class LayoutRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly LayoutWriteLock $lock,
    ) {
    }

    /**
     * @return array{surface: string, target: string, blocks: ?list<array<string,mixed>>,
     *     settings: array<string,mixed>, lock_version: int, updated_by: ?string, updated_at: ?string}|null
     */
    public function find(string $surface, string $target): ?array
    {
        $row = $this->db->table('layouts')
            ->where('surface', '=', $surface)
            ->where('target', '=', $target)
            ->first();
        return $row === null ? null : self::decode($row);
    }

    /** The stored version: 0 when there is no row. */
    public function version(string $surface, string $target): int
    {
        return $this->find($surface, $target)['lock_version'] ?? 0;
    }

    /**
     * Write a layout at `$expected` and return the new version. `$expected` 0 creates; a tombstone
     * row is written like any other (the layout is made again, its version continuing).
     *
     * @param list<array<string,mixed>> $blocks
     * @param array<string,mixed> $settings
     * @throws LayoutVersionConflict when the stored version is not `$expected`
     */
    public function saveExpected(
        string $surface,
        string $target,
        array $blocks,
        array $settings,
        int $expected,
        ?string $by,
    ): int {
        $this->assertLocked($surface, $target);
        $current = $this->version($surface, $target);
        $exists = $this->find($surface, $target) !== null;
        if ($current !== $expected || ($expected === 0 && $exists)) {
            throw new LayoutVersionConflict($current);
        }
        $now = gmdate('Y-m-d H:i:s');
        $values = [
            'blocks' => json_encode(array_values($blocks), JSON_THROW_ON_ERROR),
            'settings' => json_encode((object) $settings, JSON_THROW_ON_ERROR),
            'lock_version' => $expected + 1,
            'updated_by' => $by,
            'updated_at' => $now,
        ];
        if ($expected === 0) {
            $this->db->table('layouts')->insert($values + [
                'id' => Utils::generateNanoID(),
                'surface' => $surface,
                'target' => $target,
                'created_at' => $now,
            ]);
            return 1;
        }
        $affected = $this->db->table('layouts')
            ->where('surface', '=', $surface)
            ->where('target', '=', $target)
            ->where('lock_version', '=', $expected)
            ->update($values);
        if ($affected < 1) {
            throw new LayoutVersionConflict($this->version($surface, $target));
        }
        return $expected + 1;
    }

    /**
     * Remove a layout at `$expected`: the row stays, `blocks` null, the version bumped.
     *
     * @throws LayoutVersionConflict
     */
    public function tombstone(string $surface, string $target, int $expected, ?string $by): int
    {
        $this->assertLocked($surface, $target);
        $affected = $expected === 0 ? 0 : $this->db->table('layouts')
            ->where('surface', '=', $surface)
            ->where('target', '=', $target)
            ->where('lock_version', '=', $expected)
            ->update([
                'blocks' => null,
                'lock_version' => $expected + 1,
                'updated_by' => $by,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
        if ($affected < 1) {
            throw new LayoutVersionConflict($this->version($surface, $target));
        }
        return $expected + 1;
    }

    /**
     * Replace a live layout's blocks at `$expected` — the block-document walkers' write (§5.7).
     * False when it moved on or was removed: nothing written.
     *
     * @param list<array<string,mixed>> $blocks
     */
    public function persistBlocks(string $surface, string $target, int $expected, array $blocks): bool
    {
        return $this->lock->within($surface, $target, fn (): bool => $this->db->table('layouts')
            ->where('surface', '=', $surface)
            ->where('target', '=', $target)
            ->where('lock_version', '=', $expected)
            ->whereNotNull('blocks')
            ->update([
                'blocks' => json_encode(array_values($blocks), JSON_THROW_ON_ERROR),
                'lock_version' => $expected + 1,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]) >= 1);
    }

    /** @return list<array<string,mixed>> every layout that is not a tombstone */
    public function live(): array
    {
        $rows = $this->db->table('layouts')->whereNotNull('blocks')->orderBy('surface', 'ASC')->get();
        return array_values(array_map(self::decode(...), $rows));
    }

    /** @return list<array<string,mixed>> the layouts of one content type's entries, tombstones included */
    public function forType(string $typeSlug): array
    {
        $rows = $this->db->table('layouts')
            ->where('surface', '=', 'entry')
            ->where('target', '=', $typeSlug)
            ->get();
        return array_values(array_map(self::decode(...), $rows));
    }

    private function assertLocked(string $surface, string $target): void
    {
        if (!$this->lock->isHeld($surface, $target)) {
            throw new \LogicException("Layout {$surface}:{$target} written outside its write lock.");
        }
    }

    /**
     * @param array<string,mixed> $row
     * @return array{surface: string, target: string, blocks: ?list<array<string,mixed>>,
     *     settings: array<string,mixed>, lock_version: int, updated_by: ?string, updated_at: ?string}
     */
    private static function decode(array $row): array
    {
        $blocks = is_string($row['blocks'] ?? null) ? json_decode($row['blocks'], true) : ($row['blocks'] ?? null);
        $settings = is_string($row['settings'] ?? null)
            ? json_decode($row['settings'], true)
            : ($row['settings'] ?? []);
        return [
            'surface' => (string) $row['surface'],
            'target' => (string) $row['target'],
            'blocks' => is_array($blocks) ? array_values($blocks) : null,
            'settings' => is_array($settings) ? $settings : [],
            'lock_version' => (int) ($row['lock_version'] ?? 0),
            'updated_by' => isset($row['updated_by']) ? (string) $row['updated_by'] : null,
            'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
        ];
    }
}

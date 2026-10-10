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

    /**
     * A layout's frame settings, only while it is still at the version read (custom palette spec
     * §4.4: Replace rewrites a frame's colours): false when it moved on or became a tombstone.
     *
     * @param array<string,mixed> $settings
     */
    public function persistSettings(string $surface, string $target, int $expected, array $settings): bool
    {
        return $this->lock->within($surface, $target, fn (): bool => $this->db->table('layouts')
            ->where('surface', '=', $surface)
            ->where('target', '=', $target)
            ->where('lock_version', '=', $expected)
            ->whereNotNull('blocks')
            ->update([
                'settings' => json_encode((object) $settings, JSON_THROW_ON_ERROR),
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

    /**
     * The targets of a surface that hold a live layout — what is kept, without reading any blocks.
     *
     * @return list<string>
     */
    public function liveTargets(string $surface): array
    {
        $rows = $this->db->table('layouts')
            ->select(['target'])
            ->where('surface', '=', $surface)
            ->whereNotNull('blocks')
            ->orderBy('target', 'ASC')
            ->get();
        return array_values(array_map(static fn (array $row): string => (string) $row['target'], $rows));
    }

    /**
     * Every layout that follows one content type, tombstones included: its entries' (`entry:{type}`),
     * its listing's (`listing:{type}`) and its archives' (`archive:{type}:{field}`).
     *
     * @return list<array<string,mixed>>
     */
    public function forType(string $typeSlug): array
    {
        // One parenthesised condition: the builder joins raw conditions unwrapped, and a bare OR
        // here would escape the workspace filter the tenancy hook adds to every query of the table.
        $rows = $this->db->table('layouts')
            ->whereRaw(
                "((surface IN ('entry', 'listing') AND target = ?) OR (surface = 'archive' AND target LIKE ?))",
                [$typeSlug, addcslashes($typeSlug, '%_\\') . ':%'],
            )
            ->orderBy('surface', 'ASC')
            ->orderBy('target', 'ASC')
            ->get();
        return array_values(array_map(self::decode(...), $rows));
    }

    /**
     * Move a live layout to another target of its surface — an archived field renamed (spec §5.1: a
     * version never goes backwards). Under both targets' write locks, taken in key order: the
     * destination (created, or its tombstone updated in place) takes the layout at a version above
     * either row's, and the source becomes a tombstone one version on — so an editor holding either
     * name's old version meets a conflict. Nothing moves when the source is absent or a tombstone.
     *
     * @return array{from: int, to: int}|null the two new versions; null when nothing moved
     * @throws LayoutBindingConflict when the destination holds a live layout
     */
    public function move(string $surface, string $from, string $to, ?string $by): ?array
    {
        $first = $this->lock->key($surface, $from) <= $this->lock->key($surface, $to) ? $from : $to;
        $second = $first === $from ? $to : $from;
        return $this->lock->within($surface, $first, fn (): ?array => $this->lock->within(
            $surface,
            $second,
            function () use ($surface, $from, $to, $by): ?array {
                $source = $this->find($surface, $from);
                if ($source === null || $source['blocks'] === null) {
                    return null;
                }
                $destination = $this->find($surface, $to);
                if ($destination !== null && $destination['blocks'] !== null) {
                    throw new LayoutBindingConflict([$to => ["{$surface}:{$to}"]]);
                }
                $now = gmdate('Y-m-d H:i:s');
                $toVersion = max($source['lock_version'], $destination['lock_version'] ?? 0) + 1;
                $values = [
                    'blocks' => json_encode($source['blocks'], JSON_THROW_ON_ERROR),
                    'settings' => json_encode((object) $source['settings'], JSON_THROW_ON_ERROR),
                    'lock_version' => $toVersion,
                    'updated_by' => $by,
                    'updated_at' => $now,
                ];
                if ($destination === null) {
                    $this->db->table('layouts')->insert($values + [
                        'id' => Utils::generateNanoID(),
                        'surface' => $surface,
                        'target' => $to,
                        'created_at' => $now,
                    ]);
                } else {
                    $this->db->table('layouts')->where('surface', '=', $surface)->where('target', '=', $to)
                        ->where('lock_version', '=', $destination['lock_version'])->update($values);
                }
                $this->db->table('layouts')->where('surface', '=', $surface)->where('target', '=', $from)
                    ->where('lock_version', '=', $source['lock_version'])->update([
                        'blocks' => null,
                        'lock_version' => $source['lock_version'] + 1,
                        'updated_by' => $by,
                        'updated_at' => $now,
                    ]);
                return ['from' => $source['lock_version'] + 1, 'to' => $toVersion];
            },
        ));
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

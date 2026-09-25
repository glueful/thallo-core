<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Regions;

use Glueful\Database\Connection;
use Thallo\Core\Content\Blocks\Sources\BlockContentTypes;

/**
 * regions rows: {slug, blocks JSON, settings JSON}. No draft state, no
 * locale (global-regions spec §3/§8) — a save is the live region.
 */
final class RegionRepository
{
    private readonly RegionWriteLock $lock;

    public function __construct(
        private readonly Connection $db,
        /** Style class references a save introduces are checked at the write (spec §4.5); null = unchecked. */
        private readonly ?\Thallo\Core\Content\Style\Classes\StyleClassReferenceGuard $guard = null,
        ?RegionWriteLock $lock = null,
    ) {
        $this->lock = $lock ?? new RegionWriteLock($db);
    }

    /** @return array{slug: string, blocks: list<array<string,mixed>>, settings: array<string,mixed>, lock_version: int}|null */
    public function find(string $slug): ?array
    {
        $row = $this->db->table('regions')->where('slug', '=', $slug)->first();
        if ($row === null) {
            return null;
        }
        $blocks = json_decode((string) $row['blocks'], true);
        $settings = json_decode((string) $row['settings'], true);
        return [
            'slug' => (string) $row['slug'],
            'blocks' => is_array($blocks) ? array_values($blocks) : [],
            'settings' => is_array($settings) ? $settings : [],
            'lock_version' => (int) ($row['lock_version'] ?? 0),
        ];
    }

    /**
     * @param list<array<string,mixed>> $blocks
     * @param array<string,mixed> $settings
     */
    public function save(string $slug, array $blocks, array $settings, ?string $updatedBy): void
    {
        $payload = [
            // Empty settings must round-trip as a JSON object, not [].
            'blocks' => json_encode(array_values($blocks)),
            'settings' => json_encode($settings === [] ? (object) [] : $settings),
            'updated_at' => date('Y-m-d H:i:s'),
            'updated_by' => $updatedBy,
        ];
        // Every writer bumps lock_version (spec §4.5): a job's conditional write on a region
        // saved since is refused. This unconditional form (starter seeding and updates, account-
        // link retirement) is last-write-wins; it runs under the region lock like every writer.
        $this->lock->within(function () use ($slug, $blocks, $payload): void {
            for ($attempt = 0; $attempt < 3; $attempt++) {
                $existing = $this->db->table('regions')->where('slug', '=', $slug)->first();
                $before = $existing === null
                    ? []
                    : array_values(BlockContentTypes::decode($existing['blocks'] ?? null));
                $this->guard?->assertBlocksWritable($before, array_values($blocks));
                if ($existing === null) {
                    $this->db->table('regions')->insert($payload + ['slug' => $slug, 'lock_version' => 0]);
                    return;
                }
                $current = (int) ($existing['lock_version'] ?? 0);
                $affected = $this->db->table('regions')
                    ->where('slug', '=', $slug)
                    ->where('lock_version', '=', $current)
                    ->update($payload + ['lock_version' => $current + 1]);
                if ($affected >= 1) {
                    return;
                }
            }
            throw new \RuntimeException("region {$slug} kept changing while saving; try again");
        });
    }

    /**
     * Write a region against the version the caller loaded (regions-stage spec §4.5): `null`
     * means "the row must not exist yet". Must run inside {@see RegionWriteLock::within()} — the
     * caller checks both regions and validates the whole candidate under the same lock. Returns
     * the new version; throws {@see RegionVersionConflict} and writes nothing when it has moved.
     *
     * @param list<array<string,mixed>> $blocks
     * @param array<string,mixed> $settings
     */
    public function saveExpected(string $slug, array $blocks, array $settings, ?int $expected, ?string $updatedBy): int
    {
        if (!$this->db->withinTransaction() || !$this->lock->isHeld()) {
            throw new \LogicException('RegionRepository::saveExpected must run inside RegionWriteLock::within()');
        }
        $payload = [
            'blocks' => json_encode(array_values($blocks)),
            'settings' => json_encode($settings === [] ? (object) [] : $settings),
            'updated_at' => date('Y-m-d H:i:s'),
            'updated_by' => $updatedBy,
        ];
        $existing = $this->db->table('regions')->where('slug', '=', $slug)->first();
        $before = $existing === null ? [] : array_values(BlockContentTypes::decode($existing['blocks'] ?? null));
        if ($expected === null) {
            if ($existing !== null) {
                throw new RegionVersionConflict([$slug]);
            }
            $this->guard?->assertBlocksWritable($before, array_values($blocks));
            $this->db->table('regions')->insert($payload + ['slug' => $slug, 'lock_version' => 0]);
            return 0;
        }
        if ($existing === null || (int) ($existing['lock_version'] ?? 0) !== $expected) {
            throw new RegionVersionConflict([$slug]);
        }
        $this->guard?->assertBlocksWritable($before, array_values($blocks));
        $affected = $this->db->table('regions')
            ->where('slug', '=', $slug)
            ->where('lock_version', '=', $expected)
            ->update($payload + ['lock_version' => $expected + 1]);
        if ($affected < 1) {
            throw new RegionVersionConflict([$slug]);
        }
        return $expected + 1;
    }
}

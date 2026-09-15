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
    public function __construct(
        private readonly Connection $db,
        /** Style class references a save introduces are checked at the write (spec §4.5); null = unchecked. */
        private readonly ?\Thallo\Core\Content\Style\Classes\StyleClassReferenceGuard $guard = null,
    ) {
    }

    /** @return array{slug: string, blocks: list<array<string,mixed>>, settings: array<string,mixed>}|null */
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
        // saved since is refused. Region saves keep last-write-wins between people, so a raced
        // bump re-reads and retries rather than failing the save.
        $this->db->transaction(function () use ($slug, $blocks, $payload): void {
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
}

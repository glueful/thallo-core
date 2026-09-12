<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Regions;

use Glueful\Database\Connection;

/**
 * regions rows: {slug, blocks JSON, settings JSON}. No draft state, no
 * locale (global-regions spec §3/§8) — a save is the live region.
 */
final class RegionRepository
{
    public function __construct(private readonly Connection $db)
    {
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
        $existing = $this->db->table('regions')->where('slug', '=', $slug)->first();
        if ($existing === null) {
            $this->db->table('regions')->insert($payload + ['slug' => $slug]);
        } else {
            $this->db->table('regions')->where('slug', '=', $slug)->update($payload);
        }
    }
}

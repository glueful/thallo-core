<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Palette\Sources;

use Glueful\Database\Connection;
use Thallo\Core\Content\Blocks\Sources\BlockContentTypes;
use Thallo\Core\Content\Blocks\Sources\BlockDocumentSource;
use Thallo\Core\Content\Blocks\Sources\DocumentRef;
use Thallo\Core\Content\Regions\RegionWriteLock;
use Thallo\Core\Content\Schema\ContentTypeSchema;

/**
 * A region's own style frame (custom palette spec §4.4): the header's and footer's `settings`, as a
 * document of one `settings` key, so Replace rewrites a region's colours as it rewrites its blocks.
 */
final class RegionSettingsSource implements BlockDocumentSource
{
    public const ID = 'region_settings';

    private readonly RegionWriteLock $lock;

    public function __construct(private readonly Connection $db, ?RegionWriteLock $lock = null)
    {
        $this->lock = $lock ?? new RegionWriteLock($db);
    }

    public function id(): string
    {
        return self::ID;
    }

    public function each(callable $fn): void
    {
        $rows = $this->db->table('regions')
            ->select(['slug', 'settings', 'lock_version'])
            ->orderBy('slug', 'ASC')
            ->get();
        foreach ($rows as $row) {
            $fn(new DocumentRef(
                self::ID,
                (string) $row['slug'],
                null,
                (string) (int) ($row['lock_version'] ?? 0),
                ContentTypeSchema::fromArray([]),
                ['settings' => BlockContentTypes::decode($row['settings'] ?? null)],
            ));
        }
    }

    public function persist(DocumentRef $ref, array $fields, ?string $actor = null): bool
    {
        $expected = (int) $ref->revision;
        $settings = is_array($fields['settings'] ?? null) ? $fields['settings'] : [];
        // Under the region lock like every region writer, conditional on the version read.
        return $this->lock->within(fn (): bool => $this->db->table('regions')
            ->where('slug', '=', $ref->sourceId)
            ->where('lock_version', '=', $expected)
            ->update([
                'settings' => json_encode((object) $settings, JSON_THROW_ON_ERROR),
                'lock_version' => $expected + 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ]) >= 1);
    }
}

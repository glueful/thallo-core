<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Blocks\Sources;

use Glueful\Database\Connection;

/** Entry drafts of every non-deleted entry whose type carries blocks; persisted by lock version. */
final class EntryDraftsSource implements BlockDocumentSource
{
    public const ID = 'entry_draft';

    public function __construct(
        private readonly Connection $db,
        private readonly BlockContentTypes $types,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function each(callable $fn): void
    {
        foreach ($this->types->all() as $type) {
            $rows = $this->db->table('entry_drafts as d')
                ->join('entries as e', 'e.uuid', '=', 'd.entry_uuid')
                ->select(['d.entry_uuid', 'd.locale', 'd.fields', 'd.lock_version'])
                ->where('e.content_type_uuid', '=', $type['uuid'])
                ->where('e.status', '!=', 'deleted')
                ->get();
            foreach ($rows as $row) {
                $fn(new DocumentRef(
                    self::ID,
                    (string) $row['entry_uuid'],
                    (string) $row['locale'],
                    (string) (int) $row['lock_version'],
                    $type['schema'],
                    BlockContentTypes::decode($row['fields']),
                    ['content_type' => $type['slug']],
                ));
            }
        }
    }

    public function persist(DocumentRef $ref, array $fields, ?string $actor = null): bool
    {
        $expected = (int) $ref->revision;
        $affected = $this->db->table('entry_drafts')
            ->where('entry_uuid', '=', $ref->sourceId)
            ->where('locale', '=', (string) $ref->locale)
            ->where('lock_version', '=', $expected)
            ->update([
                'fields' => json_encode($fields, JSON_THROW_ON_ERROR),
                'lock_version' => $expected + 1,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
        return $affected >= 1;
    }
}

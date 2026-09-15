<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Blocks\Sources;

use Glueful\Database\Connection;

/**
 * Every retained entry version of every non-deleted entry whose type carries blocks — the
 * published one and the history behind it — rewritten in place, conditioned on the content
 * read. A version stays the version it was; only its blocks follow the schema.
 */
final class EntryVersionsSource implements BlockDocumentSource
{
    public const ID = 'entry_version';

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
            $rows = $this->db->table('entry_versions as v')
                ->join('entries as e', 'e.uuid', '=', 'v.entry_uuid')
                ->select(['v.uuid', 'v.entry_uuid', 'v.locale', 'v.version', 'v.fields', 'v.lock_version'])
                ->where('e.content_type_uuid', '=', $type['uuid'])
                ->where('e.status', '!=', 'deleted')
                ->orderBy('v.id', 'ASC')
                ->get();
            foreach ($rows as $row) {
                $fields = BlockContentTypes::decode($row['fields']);
                $fn(new DocumentRef(
                    self::ID,
                    (string) $row['uuid'],
                    (string) $row['locale'],
                    (string) (int) ($row['lock_version'] ?? 0),
                    $type['schema'],
                    $fields,
                    [
                        'content_type' => $type['slug'],
                        'entry_uuid' => (string) $row['entry_uuid'],
                        'version' => (int) $row['version'],
                    ],
                ));
            }
        }
    }

    public function persist(DocumentRef $ref, array $fields, ?string $actor = null): bool
    {
        // Conditional on the lock version each() handed out (spec §4.5).
        $expected = (int) $ref->revision;
        $affected = $this->db->table('entry_versions')
            ->where('uuid', '=', $ref->sourceId)
            ->where('lock_version', '=', $expected)
            ->update([
                'fields' => json_encode($fields, JSON_THROW_ON_ERROR),
                'lock_version' => $expected + 1,
            ]);
        return $affected >= 1;
    }
}

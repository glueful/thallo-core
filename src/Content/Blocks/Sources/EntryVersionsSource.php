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
                ->select(['v.uuid', 'v.entry_uuid', 'v.locale', 'v.version', 'v.fields'])
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
                    self::fingerprint($fields),
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
        $written = false;
        $this->db->transaction(function () use ($ref, $fields, &$written): void {
            $row = $this->db->table('entry_versions')->select(['fields'])->where('uuid', '=', $ref->sourceId)->first();
            if ($row === null || self::fingerprint(BlockContentTypes::decode($row['fields'])) !== $ref->revision) {
                return;
            }
            $this->db->table('entry_versions')
                ->where('uuid', '=', $ref->sourceId)
                ->update(['fields' => json_encode($fields, JSON_THROW_ON_ERROR)]);
            $written = true;
        });
        return $written;
    }

    /** @param array<string,mixed> $fields */
    public static function fingerprint(array $fields): string
    {
        return sha1((string) json_encode($fields));
    }
}

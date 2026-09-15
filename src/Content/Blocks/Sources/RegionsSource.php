<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Blocks\Sources;

use Glueful\Database\Connection;
use Thallo\Core\Content\Schema\ContentTypeSchema;

/**
 * The global regions (header, footer): one blocks list each, presented as a document with a
 * single `blocks` field so every walker treats a region like any other block-bearing document.
 */
final class RegionsSource implements BlockDocumentSource
{
    public const ID = 'region';

    private ?ContentTypeSchema $schema = null;

    public function __construct(private readonly Connection $db)
    {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function each(callable $fn): void
    {
        $rows = $this->db->table('regions')
            ->select(['slug', 'blocks', 'schema_stamp', 'lock_version'])
            ->orderBy('slug', 'ASC')
            ->get();
        foreach ($rows as $row) {
            $fields = self::document($row);
            $fn(new DocumentRef(
                self::ID,
                (string) $row['slug'],
                null,
                (string) (int) ($row['lock_version'] ?? 0),
                $this->schema(),
                $fields,
            ));
        }
    }

    public function persist(DocumentRef $ref, array $fields, ?string $actor = null): bool
    {
        // Conditional on the lock version each() handed out (spec §4.5): a region saved since
        // is a refused write the caller records and retries from a fresh read.
        $expected = (int) $ref->revision;
        $blocks = is_array($fields['blocks'] ?? null) ? array_values($fields['blocks']) : [];
        $stamp = is_array($fields['_schema'] ?? null) ? $fields['_schema'] : null;
        $affected = $this->db->table('regions')
            ->where('slug', '=', $ref->sourceId)
            ->where('lock_version', '=', $expected)
            ->update([
                'blocks' => json_encode($blocks, JSON_THROW_ON_ERROR),
                'schema_stamp' => $stamp === null ? null : json_encode($stamp, JSON_THROW_ON_ERROR),
                'lock_version' => $expected + 1,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        return $affected >= 1;
    }

    /**
     * The region row as a document: its blocks, and the schema stamp when it carries one.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function document(array $row): array
    {
        $fields = ['blocks' => array_values(BlockContentTypes::decode($row['blocks'] ?? null))];
        $stamp = BlockContentTypes::decode($row['schema_stamp'] ?? null);
        if ($stamp !== []) {
            $fields['_schema'] = $stamp;
        }
        return $fields;
    }

    /** A region's document schema: one blocks field named `blocks`. */
    public function schema(): ContentTypeSchema
    {
        return $this->schema ??= ContentTypeSchema::fromArray([['name' => 'blocks', 'type' => 'blocks']]);
    }
}

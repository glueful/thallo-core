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
        $rows = $this->db->table('regions')->select(['slug', 'blocks', 'schema_stamp'])->orderBy('slug', 'ASC')->get();
        foreach ($rows as $row) {
            $blocks = BlockContentTypes::decode($row['blocks']);
            $fields = ['blocks' => array_values($blocks)];
            $stamp = BlockContentTypes::decode($row['schema_stamp'] ?? null);
            if ($stamp !== []) {
                $fields['_schema'] = $stamp;
            }
            $fn(new DocumentRef(
                self::ID,
                (string) $row['slug'],
                null,
                EntryVersionsSource::fingerprint($fields),
                $this->schema(),
                $fields,
            ));
        }
    }

    public function persist(DocumentRef $ref, array $fields, ?string $actor = null): bool
    {
        $written = false;
        $this->db->transaction(function () use ($ref, $fields, &$written): void {
            $row = $this->db->table('regions')->select(['blocks'])->where('slug', '=', $ref->sourceId)->first();
            $current = ['blocks' => array_values(BlockContentTypes::decode($row['blocks'] ?? null))];
            if ($row === null || EntryVersionsSource::fingerprint($current) !== $ref->revision) {
                return;
            }
            $blocks = is_array($fields['blocks'] ?? null) ? array_values($fields['blocks']) : [];
            $stamp = is_array($fields['_schema'] ?? null) ? $fields['_schema'] : null;
            $this->db->table('regions')->where('slug', '=', $ref->sourceId)->update([
                'blocks' => json_encode($blocks, JSON_THROW_ON_ERROR),
                'schema_stamp' => $stamp === null ? null : json_encode($stamp, JSON_THROW_ON_ERROR),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $written = true;
        });
        return $written;
    }

    /** A region's document schema: one blocks field named `blocks`. */
    public function schema(): ContentTypeSchema
    {
        return $this->schema ??= ContentTypeSchema::fromArray([['name' => 'blocks', 'type' => 'blocks']]);
    }
}

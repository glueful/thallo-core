<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Delivery;

use Glueful\Database\Connection;
use Thallo\Contracts\Delivery\MediaUrlBatchResolver;
use Thallo\Core\Content\Schema\ContentTypeSchema;

/**
 * Describes the asset fields a delivery caller named in `?expand`: each blob uuid becomes
 * `{uuid, url, alt, caption, mime_type}`. Only on request — an asset field is its bare uuid
 * otherwise — and only for a file the public may fetch (the {@see MediaUrlBatchResolver}
 * rule rendered pages use); anything else expands to null, as an unpublished reference does.
 *
 * Top-level entry fields only: assets inside blocks or expanded references stay uuids.
 * Every description is fingerprinted onto {@see ExpandedTargets} so editing a file's words
 * changes the embedding response's ETag.
 */
final class AssetExpander
{
    public function __construct(
        private readonly Connection $db,
        private readonly MediaUrlBatchResolver $urls,
    ) {
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<string> $requested top-level field names from `?expand`
     * @return list<array<string,mixed>>
     */
    public function expand(
        array $rows,
        ContentTypeSchema $schema,
        array $requested,
        ?ExpandedTargets $expanded = null,
    ): array {
        $fields = [];
        foreach ($schema->fields() as $field) {
            if ($field->type === 'asset' && in_array($field->name, $requested, true)) {
                $fields[] = $field->name;
            }
        }
        if ($fields === [] || $rows === []) {
            return $rows;
        }

        $uuids = [];
        foreach ($rows as $row) {
            foreach ($fields as $field) {
                $value = $row['fields'][$field] ?? null;
                foreach (is_array($value) ? $value : [$value] as $uuid) {
                    if (is_string($uuid) && $uuid !== '') {
                        $uuids[$uuid] = true;
                    }
                }
            }
        }
        $described = $this->describe(array_keys($uuids));

        foreach ($rows as $i => $row) {
            foreach ($fields as $field) {
                if (!is_array($row['fields'] ?? null) || !array_key_exists($field, $row['fields'])) {
                    continue;
                }
                $value = $row['fields'][$field];
                $rows[$i]['fields'][$field] = is_array($value)
                    ? array_map(fn(mixed $v): ?array => $this->one($v, $described, $expanded), array_values($value))
                    : $this->one($value, $described, $expanded);
            }
        }
        return $rows;
    }

    /**
     * @param array<string, array<string,string>> $described
     * @return array<string,string>|null
     */
    private function one(mixed $uuid, array $described, ?ExpandedTargets $expanded): ?array
    {
        if (!is_string($uuid) || !isset($described[$uuid])) {
            return null;
        }
        $expanded?->addAsset($uuid, sha1((string) json_encode($described[$uuid])));
        return $described[$uuid];
    }

    /**
     * @param list<string> $uuids
     * @return array<string, array{uuid: string, url: string, alt: string, caption: string, mime_type: string}>
     */
    private function describe(array $uuids): array
    {
        $urls = [];
        foreach (array_chunk($uuids, 100) as $chunk) {
            $urls += $this->urls->urls($chunk);
        }
        if ($urls === []) {
            return [];
        }
        $servable = array_keys($urls);
        $mimes = array_column(
            $this->db->table('blobs')->select(['uuid', 'mime_type'])->whereIn('uuid', $servable)->get(),
            'mime_type',
            'uuid',
        );
        $words = [];
        $meta = $this->db->table('media_meta')
            ->select(['blob_uuid', 'alt_text', 'caption'])
            ->whereIn('blob_uuid', $servable)
            ->get();
        foreach ($meta as $row) {
            $words[(string) $row['blob_uuid']] = $row;
        }

        $out = [];
        foreach ($urls as $uuid => $url) {
            $out[$uuid] = [
                'uuid' => $uuid,
                'url' => $url,
                'alt' => trim((string) ($words[$uuid]['alt_text'] ?? '')),
                'caption' => trim((string) ($words[$uuid]['caption'] ?? '')),
                'mime_type' => (string) ($mimes[$uuid] ?? ''),
            ];
        }
        return $out;
    }
}

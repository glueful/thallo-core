<?php

declare(strict_types=1);

namespace Thallo\Core\Content\ImportExport;

use Thallo\Core\Content\Repositories\ReferenceProjectionRepository;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\ImportExport\Contracts\ExporterInterface;
use Glueful\Extensions\ImportExport\Files\NdjsonWriter;
use Glueful\Helpers\Utils;
use Glueful\Extensions\ImportExport\Support\ExportBatch;
use Glueful\Extensions\ImportExport\Support\ExportBatchResult;
use Glueful\Extensions\ImportExport\Support\ExportContext;
use Glueful\Extensions\ImportExport\Support\ExportOptions;
use Glueful\Extensions\ImportExport\Support\ExportPlan;

final class ContentExporter implements ExporterInterface
{
    private const TABLES = [
        'content_types' => ['kind' => 'content_type', 'json' => ['schema']],
        'entries' => ['kind' => 'entry', 'json' => []],
        'entry_drafts' => ['kind' => 'entry_draft', 'json' => ['fields']],
        'entry_versions' => ['kind' => 'entry_version', 'json' => ['fields']],
        'entry_publications' => ['kind' => 'entry_publication', 'json' => []],
        'entry_routes' => ['kind' => 'entry_route', 'json' => []],
        'entry_references' => ['kind' => 'entry_reference', 'json' => []],
    ];

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly Connection $db,
    ) {
    }

    public function key(): string
    {
        return 'thallo.content';
    }

    public function label(): string
    {
        return 'Thallo Content Bundle';
    }

    public function plan(ExportOptions $options): ExportPlan
    {
        $total = $this->totalRecords();
        $batchSize = max(1, $options->batchSize);
        $batches = [];
        for ($offset = 0, $sequence = 1; $offset < $total; $offset += $batchSize, $sequence++) {
            $batches[] = new ExportBatch(
                uuid: $this->batchUuid(),
                jobUuid: 'pending',
                sequence: $sequence,
                offset: $offset,
                limit: $batchSize,
            );
        }

        return new ExportPlan($total, $batches, retryable: false, metadata: [
            'format' => 'ndjson',
            'record_kinds' => [...array_column(self::TABLES, 'kind'), 'asset_manifest'],
        ]);
    }

    public function process(ExportBatch $batch, ExportContext $context): ExportBatchResult
    {
        $records = $this->windowedRecords($batch->offset, $batch->limit);
        $path = $this->resultPath($context->jobUuid, $batch->sequence);
        $absolute = $this->context->getBasePath() . '/storage/' . $path;
        $directory = dirname($absolute);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException(sprintf('Unable to create export directory "%s".', $directory));
        }

        (new NdjsonWriter())->write($absolute, $records);

        return new ExportBatchResult(
            processedRecords: count($records),
            failedRecords: 0,
            errors: [],
            resultPath: $path,
            metadata: ['record_kinds' => array_values(array_unique(array_column($records, 'kind')))]
        );
    }

    private function totalRecords(): int
    {
        $total = 0;
        foreach (array_keys(self::TABLES) as $table) {
            $total += $this->db->table($table)->count();
        }
        return $total + count($this->assetManifestRows());
    }

    /**
     * Materialize only the [offset, offset+limit) slice of the global record sequence
     * (all tables in TABLES order, then the asset manifest). Each overlapping table segment is read
     * with its own LIMIT/OFFSET so peak memory is O(limit) rather than the whole dataset — the old
     * array_slice(records()) loaded every table in full on every batch. Ordering and content are
     * identical to the full sequence.
     *
     * @return list<array{kind:string,data:array<string,mixed>}>
     */
    private function windowedRecords(int $offset, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        $end = $offset + $limit;
        $records = [];
        $segmentStart = 0;

        foreach (self::TABLES as $table => $spec) {
            $count = $this->db->table($table)->count();
            $from = max($offset, $segmentStart);
            $to = min($end, $segmentStart + $count);
            if ($from < $to) {
                $rows = $this->db->table($table)
                    ->orderBy('id', 'ASC')
                    ->limit($to - $from)
                    ->offset($from - $segmentStart)
                    ->get();
                foreach ($rows as $row) {
                    $records[] = [
                        'kind' => $spec['kind'],
                        'data' => $this->decodeJsonColumns($row, $spec['json']),
                    ];
                }
            }
            $segmentStart += $count;
            if ($segmentStart >= $end) {
                return $records;
            }
        }

        // Asset manifest is the final segment. Its referenced-uuid set is small, so windowing it in
        // memory is fine; the expensive part (scanning drafts/versions to build it) is chunked in
        // referencedAssetUuids().
        $localFrom = max(0, $offset - $segmentStart);
        $localLen = ($end - $segmentStart) - $localFrom;
        if ($localLen > 0) {
            foreach (array_slice($this->assetManifestRows(), $localFrom, $localLen) as $row) {
                $records[] = ['kind' => 'asset_manifest', 'data' => $row];
            }
        }

        return $records;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function assetManifestRows(): array
    {
        $uuids = $this->referencedAssetUuids();
        if ($uuids === []) {
            return [];
        }

        $rows = $this->db->table('blobs')
            ->whereIn('uuid', $uuids)
            ->orderBy('uuid', 'ASC')
            ->get();

        return array_map(static function (array $row): array {
            $row['fetch_path'] = (string) ($row['url'] ?? '');
            return $row;
        }, $rows);
    }

    /**
     * @return list<string>
     */
    private function referencedAssetUuids(): array
    {
        $types = [];
        foreach ($this->rows('content_types') as $row) {
            $types[(string) $row['uuid']] = ContentTypeSchema::fromArray(
                is_string($row['schema'] ?? null)
                    ? (json_decode((string) $row['schema'], true) ?: [])
                    : (array) ($row['schema'] ?? [])
            );
        }

        $entryTypes = [];
        foreach ($this->rows('entries') as $row) {
            $entryTypes[(string) $row['uuid']] = (string) $row['content_type_uuid'];
        }

        $assets = [];
        foreach (['entry_drafts', 'entry_versions'] as $table) {
            // Keyset-chunk the scan so the drafts/versions `fields` columns (the bulk of the data)
            // are never all resident at once — only the small accumulated asset-uuid set is kept.
            $lastId = 0;
            do {
                $rows = $this->db->table($table)
                    ->where('id', '>', $lastId)
                    ->orderBy('id', 'ASC')
                    ->limit(500)
                    ->get();
                foreach ($rows as $row) {
                    $lastId = (int) ($row['id'] ?? $lastId);
                    $typeUuid = $entryTypes[(string) ($row['entry_uuid'] ?? '')] ?? '';
                    $schema = $types[$typeUuid] ?? null;
                    if ($schema === null) {
                        continue;
                    }
                    $fields = is_string($row['fields'] ?? null)
                        ? (json_decode((string) $row['fields'], true) ?: [])
                        : (array) ($row['fields'] ?? []);

                    foreach ($schema->fields() as $field) {
                        if ($field->type !== 'asset') {
                            continue;
                        }
                        foreach (ReferenceProjectionRepository::targets($fields[$field->name] ?? null) as $asset) {
                            $assets[$asset] = true;
                        }
                    }
                }
            } while (count($rows) === 500);
        }

        $out = array_keys($assets);
        sort($out);
        return $out;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function rows(string $table): array
    {
        return $this->db->table($table)->orderBy('id', 'ASC')->get();
    }

    /**
     * @param array<string,mixed> $row
     * @param list<string> $columns
     * @return array<string,mixed>
     */
    private function decodeJsonColumns(array $row, array $columns): array
    {
        foreach ($columns as $column) {
            if (is_string($row[$column] ?? null)) {
                $decoded = json_decode((string) $row[$column], true);
                $row[$column] = is_array($decoded) ? $decoded : [];
            }
        }
        return $row;
    }

    private function batchUuid(): string
    {
        // Random, not derived from sequence/offset: import_export_batches.uuid is globally
        // UNIQUE and rows outlive the job, so a deterministic uuid made the SECOND snapshot
        // export collide on its first batch.
        return Utils::generateNanoID(12);
    }

    private function resultPath(string $jobUuid, int $sequence): string
    {
        $job = preg_replace('/[^A-Za-z0-9_-]/', '', $jobUuid) ?: 'job';
        return sprintf('import-export/exports/%s/thallo-content-%04d.ndjson', $job, $sequence);
    }
}

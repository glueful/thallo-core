<?php

declare(strict_types=1);

namespace Thallo\Core\Content\ImportExport;

use Thallo\Core\Content\Palette\ColorTokenWalker;
use Thallo\Core\Content\Palette\Normalized;
use Thallo\Core\Content\Palette\PaletteFence;
use Thallo\Core\Content\Palette\PaletteNormalizer;
use Thallo\Core\Content\Palette\PaletteRefusal;
use Thallo\Core\Content\Palette\PaletteSnapshot;
use Thallo\Core\Content\Repositories\ReferenceProjectionRepository;
use Thallo\Core\Content\Repositories\VersionRepository;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\ImportExport\Contracts\ImporterInterface;
use Glueful\Extensions\ImportExport\Contracts\RetryableAdapterInterface;
use Glueful\Extensions\ImportExport\Files\NdjsonReader;
use Glueful\Helpers\Utils;
use Glueful\Extensions\ImportExport\Support\ImportBatch;
use Glueful\Extensions\ImportExport\Support\ImportBatchResult;
use Glueful\Extensions\ImportExport\Support\ImportContext;
use Glueful\Extensions\ImportExport\Support\ImportOptions;
use Glueful\Extensions\ImportExport\Support\ImportPlan;
use Glueful\Extensions\ImportExport\Support\ImportSource;

use function config;

final class ContentImporter implements ImporterInterface, RetryableAdapterInterface
{
    /**
     * @var array<string,array{table:string,json:list<string>,key:list<string>}>
     */
    private const KINDS = [
        'content_type' => ['table' => 'content_types', 'json' => ['schema'], 'key' => ['uuid']],
        'entry' => ['table' => 'entries', 'json' => [], 'key' => ['uuid']],
        'entry_draft' => ['table' => 'entry_drafts', 'json' => ['fields'], 'key' => ['entry_uuid', 'locale']],
        'entry_version' => ['table' => 'entry_versions', 'json' => ['fields'], 'key' => ['uuid']],
        'entry_publication' => ['table' => 'entry_publications', 'json' => [], 'key' => ['entry_uuid', 'locale']],
        'entry_route' => ['table' => 'entry_routes', 'json' => [], 'key' => ['entry_uuid', 'locale']],
        'entry_reference' => [
            'table' => 'entry_references',
            'json' => [],
            'key' => ['source_entry_uuid', 'source_field', 'target_entry_uuid'],
        ],
        'asset_manifest' => ['table' => 'blobs', 'json' => [], 'key' => ['uuid']],
    ];

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly Connection $db,
        /** The palette fence (custom palette spec §4.3): records that change what a site renders. */
        private readonly ?PaletteFence $fence = null,
        private readonly ?PaletteNormalizer $normalizer = null,
        private readonly ?VersionRepository $versions = null,
        private readonly ?ReferenceProjectionRepository $references = null,
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

    public function supports(ImportSource $source): bool
    {
        return $source->path !== '' && in_array($this->extension($source->path), ['ndjson', 'jsonl'], true);
    }

    public function plan(ImportSource $source, ImportOptions $options): ImportPlan
    {
        $total = $this->countRecords($source->path);
        $batchSize = max(1, $options->batchSize);
        $batches = [];
        for ($offset = 0, $sequence = 1; $offset < $total; $offset += $batchSize, $sequence++) {
            $batches[] = new ImportBatch(
                uuid: $this->batchUuid(),
                jobUuid: 'pending',
                sequence: $sequence,
                offset: $offset,
                limit: $batchSize,
            );
        }

        return new ImportPlan($total, $batches, retryable: true, metadata: [
            'format' => 'ndjson',
            'record_kinds' => array_keys(self::KINDS),
        ]);
    }

    public function process(ImportBatch $batch, ImportContext $context): ImportBatchResult
    {
        $records = $this->readWindow($this->sourcePathForJob($context->jobUuid), $batch->offset, $batch->limit);
        $errors = [];
        $processed = 0;

        foreach ($records as $index => $record) {
            $line = $batch->offset + $index + 1;
            try {
                [$kind, $data] = $this->validateRecord($record);
                if ($context->mode === 'commit') {
                    $this->commit($kind, $data);
                }
                $processed++;
            } catch (PaletteRefusal $e) {
                // A record naming a brand colour this site does not have (custom palette spec §4.5):
                // refused alone; the rest of the import continues.
                $errors[] = [
                    'record_number' => $line,
                    'severity' => 'error',
                    'code' => 'palette',
                    'message' => $e->getMessage(),
                    'details' => $e->errors,
                ];
            } catch (\Throwable $e) {
                $errors[] = [
                    'record_number' => $line,
                    'severity' => 'error',
                    'code' => 'content_import_failed',
                    'message' => $e->getMessage(),
                ];
            }
        }

        return new ImportBatchResult($processed, count($errors), $errors, [
            'mode' => $context->mode,
        ]);
    }

    public function retryable(): bool
    {
        return true;
    }

    /**
     * @param array<string,mixed> $record
     * @return array{0:string,1:array<string,mixed>}
     */
    private function validateRecord(array $record): array
    {
        $kind = $record['kind'] ?? null;
        if (!is_string($kind) || !isset(self::KINDS[$kind])) {
            throw new \InvalidArgumentException('Unknown Thallo content record kind.');
        }
        if (!is_array($record['data'] ?? null) || array_is_list($record['data'])) {
            throw new \InvalidArgumentException('Thallo content record data must be an object.');
        }

        $data = $record['data'];
        foreach (self::KINDS[$kind]['key'] as $column) {
            if (!isset($data[$column]) || !is_scalar($data[$column]) || (string) $data[$column] === '') {
                throw new \InvalidArgumentException(sprintf('Missing required key "%s" for %s.', $column, $kind));
            }
        }

        return [$kind, $data];
    }

    /**
     * The records that change what a site renders go through the palette fence (custom palette spec
     * §4.3, §4.5): an entry draft (its stored draft the basis); an entry version that is the current
     * publication (rewriting live content); and a publication pointer — the referenced version
     * normalised with the current publication as basis, and appended and pinned when a running
     * replacement changes it, never repinned with the slot's old colour. History is stored as given.
     *
     * @param array<string,mixed> $data
     */
    private function commit(string $kind, array $data): void
    {
        if ($this->fence === null || $this->normalizer === null) {
            $this->upsert($kind, $data);
            return;
        }
        switch ($kind) {
            case 'entry_draft':
                $this->fencedFields($kind, $data, 'entry_drafts', [
                    'entry_uuid' => (string) $data['entry_uuid'],
                    'locale' => (string) ($data['locale'] ?? ''),
                ]);
                return;
            case 'entry_version':
                $pinned = $this->db->table('entry_publications')
                    ->where('version_uuid', '=', (string) $data['uuid'])->first() !== null;
                if (!$pinned) {
                    $this->upsert($kind, $data); // history: stored as given (§4.1)
                    return;
                }
                $this->fencedFields($kind, $data, 'entry_versions', ['uuid' => (string) $data['uuid']]);
                return;
            case 'entry_publication':
                $this->fencedPointer($data);
                return;
            default:
                $this->upsert($kind, $data);
        }
    }

    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $key the stored row the record replaces
     */
    private function fencedFields(string $kind, array $data, string $table, array $key): void
    {
        $raw = $data['fields'] ?? [];
        $fields = is_array($raw) ? $raw : (json_decode((string) $raw, true) ?: []);
        $schema = $this->schemaOf((string) ($data['entry_uuid'] ?? ''));
        $this->fence?->write(
            function (PaletteSnapshot $s) use ($fields, $table, $key, $schema): Normalized {
                $query = $this->db->table($table);
                foreach ($key as $column => $value) {
                    $query->where($column, '=', $value);
                }
                $stored = $query->first();
                $basisDoc = $stored === null ? [] : (json_decode((string) $stored['fields'], true) ?: []);
                return $this->normalizer?->normalize(
                    ColorTokenWalker::KIND_ENTRY,
                    $fields,
                    $s,
                    $this->normalizer->basisOf(ColorTokenWalker::KIND_ENTRY, $schema, $basisDoc),
                    $schema,
                ) ?? new Normalized($fields, false, false);
            },
            fn (array $doc) => $this->upsert($kind, ['fields' => $doc] + $data),
        );
    }

    /** @param array<string,mixed> $data an entry_publication record */
    private function fencedPointer(array $data): void
    {
        $entry = (string) $data['entry_uuid'];
        $locale = (string) $data['locale'];
        $version = $this->db->table('entry_versions')
            ->where('uuid', '=', (string) ($data['version_uuid'] ?? ''))->first();
        if ($version === null) {
            throw new \InvalidArgumentException('The publication names a version that does not exist.');
        }
        $fields = json_decode((string) $version['fields'], true) ?: [];
        $schema = $this->schemaOf($entry);
        // The trusted basis is what the site publishes now, not the version itself: a bundle from a
        // site with other brand colours gets the same verdict for its pointer as for its draft —
        // refused where it names a slot this site does not configure — while re-importing a site's
        // own bundle (its live page already naming the colour there) still pins it.
        $current = $this->db->table('entry_publications')->where('entry_uuid', '=', $entry)
            ->where('locale', '=', $locale)->first();
        $live = $current === null ? null : $this->db->table('entry_versions')
            ->where('uuid', '=', (string) $current['version_uuid'])->first();
        $liveFields = $live === null ? [] : (json_decode((string) $live['fields'], true) ?: []);
        $this->fence?->write(
            fn (PaletteSnapshot $s): Normalized => $this->normalizer?->normalize(
                ColorTokenWalker::KIND_ENTRY,
                $fields,
                $s,
                $this->normalizer->basisOf(ColorTokenWalker::KIND_ENTRY, $schema, $liveFields),
                $schema,
            ) ?? new Normalized($fields, false, false),
            function (array $doc) use ($data, $entry, $locale, $fields, $version, $schema): void {
                if ($doc === $fields || $this->versions === null) {
                    $this->upsert('entry_publication', $data);
                    return;
                }
                // A running replacement maps the version's colours: append the normalised version and
                // pin that, never the old one.
                $this->db->transaction(function () use ($entry, $locale, $doc, $version, $schema): void {
                    $number = $this->versions?->reserveNextVersionNumber($entry, $locale) ?? 0;
                    $uuid = (string) $this->versions?->appendVersion(
                        $entry,
                        $locale,
                        $number,
                        $doc,
                        (int) ($version['schema_version'] ?? 1),
                        null,
                    );
                    $this->versions?->pin($entry, $locale, $uuid, null);
                    if ($schema !== null) {
                        $this->references?->rebuildForEntry($entry, $schema, $doc, $locale);
                    }
                });
            },
        );
    }

    private function schemaOf(string $entryUuid): ?ContentTypeSchema
    {
        $entry = $this->db->table('entries')->where('uuid', '=', $entryUuid)->first();
        $type = $entry === null ? null : $this->db->table('content_types')
            ->where('uuid', '=', (string) $entry['content_type_uuid'])->first();
        if ($type === null) {
            return null;
        }
        $schema = is_string($type['schema'] ?? null) ? json_decode($type['schema'], true) : $type['schema'];
        return is_array($schema) ? ContentTypeSchema::fromArray($schema) : null;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function upsert(string $kind, array $data): void
    {
        $spec = self::KINDS[$kind];
        $table = $spec['table'];
        unset($data['id']);
        unset($data['fetch_path']);
        foreach ($spec['json'] as $column) {
            if (isset($data[$column]) && is_array($data[$column])) {
                $data[$column] = json_encode($data[$column], JSON_THROW_ON_ERROR);
            }
        }

        if ($kind === 'asset_manifest') {
            // Hard delete (bypass soft-delete): this upserts a blob by uuid, so the old row must
            // physically go before the re-insert below — forceDelete() skips the soft-delete that
            // delete() applies to the deleted_at-bearing `blobs` table.
            //
            // Wrap the delete+insert in one transaction: process() catches per-record failures and
            // keeps going, so without atomicity a failing insert (constraint/malformed row) — or a
            // crash between the two statements — would leave the live blob metadata permanently
            // deleted while the file it points at is orphaned. The transaction rolls the delete back
            // so the original row survives an unsuccessful re-import.
            $this->db->transaction(function () use ($table, $data): void {
                $this->db->table('blobs')->where('uuid', '=', (string) $data['uuid'])->forceDelete();
                $this->db->table($table)->insert($data);
            });
            return;
        }

        $query = $this->db->table($table);
        foreach ($spec['key'] as $column) {
            $query->where($column, '=', (string) $data[$column]);
        }

        if ($query->first() === null) {
            $this->db->table($table)->insert($data);
            return;
        }

        $update = $data;
        foreach ($spec['key'] as $column) {
            unset($update[$column]);
        }
        if ($update === []) {
            return;
        }

        $query = $this->db->table($table);
        foreach ($spec['key'] as $column) {
            $query->where($column, '=', (string) $data[$column]);
        }
        $query->update($update);
    }

    private function sourcePathForJob(string $jobUuid): string
    {
        $file = $this->db->table('import_export_files')
            ->where('job_uuid', '=', $jobUuid)
            ->where('role', '=', 'source')
            ->orderBy('id')
            ->first();
        if ($file === null) {
            throw new \RuntimeException(sprintf('Import source file for job "%s" was not found.', $jobUuid));
        }

        return $this->resolveSourcePath((string) $file['disk'], (string) $file['path']);
    }

    /**
     * Read only the [offset, offset+limit) window of NDJSON records by streaming the reader (a
     * generator) rather than materializing the whole file per batch — peak memory is O(limit), not
     * O(file). Ordering is the file's own line order, so batch boundaries are stable.
     *
     * @return list<array<string,mixed>>
     */
    private function readWindow(string $path, int $offset, int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        $out = [];
        $index = 0;
        $end = $offset + $limit;
        foreach ((new NdjsonReader())->read($path) as $record) {
            if ($index >= $end) {
                break;
            }
            if ($index >= $offset) {
                $out[] = $record;
            }
            $index++;
        }

        return $out;
    }

    private function countRecords(string $path): int
    {
        $count = 0;
        foreach ((new NdjsonReader())->read($path) as $ignored) {
            $count++;
        }

        return $count;
    }

    private function resolveSourcePath(string $disk, string $path): string
    {
        if ($path !== '' && $path[0] === '/') {
            return $path;
        }

        $roots = config($this->context, 'import_export.source_roots', []);
        $root = is_array($roots) && isset($roots[$disk]) && is_string($roots[$disk]) && $roots[$disk] !== ''
            ? $roots[$disk]
            : $this->context->getBasePath() . DIRECTORY_SEPARATOR . $disk;

        return rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($path, DIRECTORY_SEPARATOR);
    }

    private function extension(string $path): string
    {
        return strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
    }

    private function batchUuid(): string
    {
        // Random, not derived from sequence/offset: import_export_batches.uuid is globally
        // UNIQUE and rows outlive the job, so a deterministic uuid made the SECOND snapshot
        // import collide on its first batch.
        return Utils::generateNanoID(12);
    }
}

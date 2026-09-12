<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Repositories;

use Thallo\Core\Content\Schema\ContentTypeSchema;
use Glueful\Database\Connection;

/**
 * Write-time projection of reference/asset fields into entry_references (V1_DESIGN §4).
 *
 * Rebuilt per (source entry, locale) inside the publish/draft-save transaction: that locale's
 * existing rows are deleted then re-inserted from the version's reference field values and asset
 * blob references, deduped on the unique
 * (source_entry_uuid, source_field, target_entry_uuid, locale). Keeping references locale-scoped is
 * what lets a multi-locale entry publish/unpublish one locale without discarding the others'
 * projections (which "what links here" and asset delete-protection depend on).
 */
final class ReferenceProjectionRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Rebuild the reference rows for one (entry, locale): clear that locale's rows, then re-insert
     * from the given fields. Other locales' rows are untouched.
     *
     * @param array<string,mixed> $fields the cleaned/published fields for $locale
     */
    public function rebuildForEntry(
        string $sourceEntryUuid,
        ContentTypeSchema $schema,
        array $fields,
        string $locale,
    ): void {
        $this->clearForEntryLocale($sourceEntryUuid, $locale);

        $rows = [];
        foreach ($schema->fields() as $f) {
            if ($f->type !== 'reference' && $f->type !== 'asset') {
                continue;
            }
            foreach (self::targets($fields[$f->name] ?? null) as $target) {
                $rows[] = [
                    'source_entry_uuid' => $sourceEntryUuid,
                    'source_field' => $f->name,
                    'target_entry_uuid' => $target,
                    'locale' => $locale,
                ];
            }
        }

        foreach ($this->dedupe($rows) as $r) {
            $this->db->table('entry_references')->insert($r);
        }
    }

    /** Delete every reference row for a source entry across ALL locales (whole-entry delete). */
    public function clearForEntry(string $sourceEntryUuid): void
    {
        $this->db->table('entry_references')
            ->where('source_entry_uuid', '=', $sourceEntryUuid)
            ->delete();
    }

    /** Delete a source entry's reference rows for ONE locale (leaves other locales intact). */
    public function clearForEntryLocale(string $sourceEntryUuid, string $locale): void
    {
        $this->db->table('entry_references')
            ->where('source_entry_uuid', '=', $sourceEntryUuid)
            ->where('locale', '=', $locale)
            ->delete();
    }

    /**
     * Reverse lookup: entry uuids that reference the target ("what links here").
     *
     * @return list<string>
     */
    public function referencesTo(string $targetEntryUuid): array
    {
        return array_values(array_unique(array_column(
            $this->db->table('entry_references')
                ->select(['source_entry_uuid'])
                ->where('target_entry_uuid', '=', $targetEntryUuid)
                ->get(),
            'source_entry_uuid'
        )));
    }

    /**
     * Projection parser for uuid strings or lists of uuid strings. V1 write validation
     * currently admits single-value reference/asset fields; this parser is intentionally
     * tolerant so projection, import/export, and future multi-value fields share one
     * target-normalization path.
     *
     * @return list<string>
     */
    public static function targets(mixed $value): array
    {
        if (is_string($value) && $value !== '') {
            return [$value];
        }
        if (is_array($value)) {
            return array_values(array_filter(
                array_map(static fn($v): string => is_string($v) ? $v : '', $value),
                static fn(string $v): bool => $v !== ''
            ));
        }
        return [];
    }

    /**
     * Dedupe on the unique (source_field, target_entry_uuid) within one source entry.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function dedupe(array $rows): array
    {
        $seen = [];
        $out = [];
        foreach ($rows as $r) {
            $k = $r['source_field'] . '|' . $r['target_entry_uuid'];
            if (!isset($seen[$k])) {
                $seen[$k] = true;
                $out[] = $r;
            }
        }
        return $out;
    }
}

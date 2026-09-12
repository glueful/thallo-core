<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Repositories;

use Thallo\Core\Content\Delivery\InvalidFilterException;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Content\Schema\FieldDefinition;
use Thallo\Core\Content\Schema\Migration\SchemaProjector;
use Glueful\Database\Connection;

/**
 * The PUBLISHED-reference projection (term-archives/facets spec §1) — the single source
 * of "published source references published term". Rebuilt per (entry, locale) from the
 * PUBLISHED version's reference fields by ProjectPublishedReferencesListener; re-driven
 * by `thallo:resync`. Reference fields only (never asset), regardless of `filterable` —
 * flipping `filterable` later must not require a backfill; endpoints gate at read time.
 *
 * The pinned version's fields are projected FORWARD through SchemaProjector (its
 * schema_version → current) before scanning: a rollback re-pins an older version whose
 * reference fields may since have been renamed/deleted, and the projection must mirror
 * what delivery actually serves (DeliveryItemShaper projects the same way; the rollback
 * path in PublishService does the equivalent for the draft-side projection).
 *
 * Read queries (facetCounts / membershipPredicate) join the TARGET's publication at
 * read time — delete hygiene here is not the liveness mechanism (spec §1: a term can
 * be unpublished without being deleted).
 */
final class PublishedReferenceRepository
{
    public function __construct(
        private readonly Connection $db,
        private readonly ContentTypeRepository $types,
        private readonly SchemaProjector $schemaProjector,
    ) {
    }

    /**
     * Rebuild the projection rows for one (entry, locale) from its PUBLISHED version:
     * clear that locale's rows, project the pinned fields forward to the current schema,
     * then re-insert. No publication (or no type) → the clear still ran, so stale rows
     * never survive. Idempotent.
     */
    public function projectFromPublished(string $entryUuid, string $typeUuid, string $locale): void
    {
        $this->clearForEntryLocale($entryUuid, $locale);

        $row = $this->db->table('entry_publications as p')
            ->join('entry_versions as v', 'v.uuid', '=', 'p.version_uuid')
            ->select(['v.fields', 'v.schema_version'])
            ->where('p.entry_uuid', '=', $entryUuid)
            ->where('p.locale', '=', $locale)
            ->first();
        if ($row === null) {
            return;
        }
        $fields = json_decode((string) $row['fields'], true);
        if (!is_array($fields)) {
            return;
        }
        $typeRow = $this->types->findByUuid($typeUuid);
        if ($typeRow === null) {
            return;
        }
        // Rollback safety: re-pinned older versions carry older schema semantics —
        // apply the migration chain (renames/deletes) so field names match the
        // CURRENT schema the scan below (and the read endpoints) use.
        $fields = $this->schemaProjector->project($typeUuid, (int) ($row['schema_version'] ?? 0), $fields);
        $schema = ContentTypeSchema::fromArray($typeRow['schema']);

        $seen = [];
        foreach ($schema->fields() as $f) {
            if ($f->type !== 'reference') {
                continue; // asset fields are never projected (spec §1)
            }
            foreach (ReferenceProjectionRepository::targets($fields[$f->name] ?? null) as $target) {
                $key = $f->name . '|' . $target;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $this->db->table('published_entry_references')->insert([
                    'source_entry_uuid' => $entryUuid,
                    'source_content_type_uuid' => $typeUuid,
                    'field' => $f->name,
                    'target_entry_uuid' => $target,
                    'locale' => $locale,
                ]);
            }
        }
    }

    public function clearForEntryLocale(string $entryUuid, string $locale): void
    {
        $this->db->table('published_entry_references')
            ->where('source_entry_uuid', '=', $entryUuid)
            ->where('locale', '=', $locale)
            ->delete();
    }

    /** Whole-entry delete: drop every locale's rows where the entry is the SOURCE. */
    public function clearForEntry(string $entryUuid): void
    {
        $this->db->table('published_entry_references')
            ->where('source_entry_uuid', '=', $entryUuid)
            ->delete();
    }

    /** Hygiene on term delete: drop rows pointing AT the entry (read-time joins stay the liveness rule). */
    public function clearForTarget(string $targetEntryUuid): void
    {
        $this->db->table('published_entry_references')
            ->where('target_entry_uuid', '=', $targetEntryUuid)
            ->delete();
    }

    /**
     * Global facet counts for one (source type, reference field, locale): entries-per-term
     * over the projection, JOINED to the target's publication in the SAME locale at read
     * time (spec §1 — an unpublished term drops out while its rows remain). The slug is
     * read from the target's published version via the field's referenceSlugField, exactly
     * like ReferenceFilterResolver. Order: count DESC, slug ASC.
     *
     * @return list<array{uuid: string, slug: ?string, count: int}>
     */
    public function facetCounts(
        string $sourceTypeUuid,
        FieldDefinition $field,
        string $targetTypeUuid,
        string $locale,
        int $limit,
    ): array {
        $slugField = $field->referenceSlugField ?? 'slug';
        // Schema identifier — interpolated (never bound) so it can hit expression indexes;
        // re-assert the safe shape first (the ReferenceFilterResolver rule).
        if (preg_match('/\A[a-z][a-z0-9_]*\z/', $slugField) !== 1) {
            throw new InvalidFilterException("unsafe reference_slug_field: '{$slugField}'");
        }
        $slugExpr = "tv.fields ->> '{$slugField}'";

        $rows = $this->db->table('published_entry_references as pr')
            ->join('entry_publications as tp', 'tp.entry_uuid', '=', 'pr.target_entry_uuid')
            ->join('entry_versions as tv', 'tv.uuid', '=', 'tp.version_uuid')
            ->join('entries as te', 'te.uuid', '=', 'pr.target_entry_uuid')
            ->selectRaw(
                "pr.target_entry_uuid as uuid, {$slugExpr} as slug, "
                . 'COUNT(DISTINCT pr.source_entry_uuid) as cnt'
            )
            ->where('pr.source_content_type_uuid', '=', $sourceTypeUuid)
            ->where('pr.field', '=', $field->name)
            ->where('pr.locale', '=', $locale)
            ->where('tp.locale', '=', $locale)
            ->where('te.status', '=', 'active')
            ->where('te.content_type_uuid', '=', $targetTypeUuid)
            // Group by the qualified source column + the output alias: bare `uuid`
            // is ambiguous against the joined tables' own uuid columns; `slug` has no
            // input-column collision so it resolves to the select alias.
            ->groupBy(['pr.target_entry_uuid', 'slug'])
            ->orderByRaw('cnt DESC, slug ASC')
            ->limit($limit)
            ->get();

        return array_map(static fn(array $r): array => [
            'uuid' => (string) $r['uuid'],
            'slug' => isset($r['slug']) ? (string) $r['slug'] : null,
            'count' => (int) $r['cnt'],
        ], $rows);
    }

    /**
     * Archive membership predicate (spec §3 pin): an EXISTS over the projection, shaped
     * to ride DeliveryRepository's compiled-filter slot. Coupled to the delivery spine
     * aliases (`p` = entry_publications) exactly like FilterCompiler's `v.fields`
     * expressions are.
     *
     * @return array{sql: string, bindings: list<mixed>}
     */
    public function membershipPredicate(string $sourceTypeUuid, string $field, string $targetEntryUuid): array
    {
        return [
            'sql' => 'EXISTS (SELECT 1 FROM published_entry_references pr'
                . ' WHERE pr.source_entry_uuid = p.entry_uuid AND pr.locale = p.locale'
                . ' AND pr.source_content_type_uuid = ? AND pr.field = ? AND pr.target_entry_uuid = ?)',
            'bindings' => [$sourceTypeUuid, $field, $targetEntryUuid],
        ];
    }
}

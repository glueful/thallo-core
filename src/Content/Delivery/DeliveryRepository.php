<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Delivery;

use Glueful\Database\Connection;
use Glueful\Database\QueryBuilder;

/**
 * The leak-proof delivery read path.
 *
 * Every query reads from `entry_publications` (the spine — only published entries
 * have a row there) joined to the pinned `entry_versions`. There is no status
 * column on the read path, so drafts/unpublished entries physically cannot be
 * returned. The repository never touches `entry_drafts`.
 */
final class DeliveryRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * Cursor/keyset list — the default, publish-churn-stable read path.
     *
     * Order comes from {@see SortCompiler} (default `published_at DESC, v.id DESC`); when
     * a decoded `$cursor` is present a keyset predicate restricts the result to rows
     * *after* the cursor's position, so paging never skips or duplicates rows even as
     * the published set changes underneath it. This is deliberately NOT the framework's
     * offset `paginate()` (unstable + O(offset)); the offset convenience path is
     * {@see paginatePublished()}.
     *
     * @param array{sql:string,bindings:list<mixed>}|null $filter compiled from FilterCompiler
     * @param array{sql:string,expr:string,direction:string,field:?string,column:?string}|null $order
     *        compiled from SortCompiler; null => default newest-first order
     * @param array{sort:scalar|null,id:int}|null $cursor decoded keyset position (Cursor::decode)
     * @return list<array<string,mixed>> newest-published first (default order)
     */
    public function listPublished(
        string $contentTypeUuid,
        string $locale,
        int $limit = 20,
        ?array $filter = null,
        ?array $order = null,
        ?array $cursor = null,
    ): array {
        $q = $this->base($contentTypeUuid, $locale);
        $this->applyFilter($q, $filter);

        $order ??= SortCompiler::defaultOrder();

        // Keyset predicate: the ORDER BY mixes the primary direction with a fixed
        // `v.id DESC` tiebreaker, so a single row-value comparison cannot express it.
        // Use the expanded equivalent: `expr <dirOp> ? OR (expr = ? AND v.id < ?)`,
        // with all values BOUND. dirOp is `<` for DESC, `>` for ASC.
        //
        // Field sorts are over an OPTIONAL JSONB field, so the sort expression can be
        // NULL (row missing the field); the default `published_at` sort cannot. The
        // ORDER BY pins nulls LAST (see SortCompiler), so we mirror that here:
        //   - cursor still in the non-null region (sort != null): also pull in the whole
        //     null tail via `OR expr IS NULL` — nulls sort after every non-null value.
        //   - cursor already inside the null tail (sort == null): only further null rows
        //     remain, paged by the `v.id` tiebreaker.
        // `published_at` is never null, so its predicate stays exactly as before.
        if ($cursor !== null) {
            $expr = $order['expr'];
            $nullable = $order['field'] !== null;

            if ($nullable && $cursor['sort'] === null) {
                $q->whereRaw("({$expr} IS NULL AND v.id < ?)", [$cursor['id']]);
            } else {
                $dirOp = $order['direction'] === 'ASC' ? '>' : '<';
                $predicate = "({$expr} {$dirOp} ? OR ({$expr} = ? AND v.id < ?)";
                $predicate .= $nullable ? " OR {$expr} IS NULL)" : ")";
                $q->whereRaw($predicate, [$cursor['sort'], $cursor['sort'], $cursor['id']]);
            }
        }

        $q->orderByRaw(substr($order['sql'], strlen('ORDER BY ')));
        $q->limit($limit);
        return array_map([$this, 'hydrate'], $q->get());
    }

    /**
     * Offset convenience path — `page`/`perPage`. Uses the framework's offset-based
     * `QueryBuilder::paginate()` (NOT hand-rolled) so it matches the admin API's
     * pagination envelope. Offset paging is not churn-stable; that is an accepted
     * trade-off for this opt-in convenience mode (use the cursor path for live feeds).
     *
     * @param array{sql:string,bindings:list<mixed>}|null $filter compiled from FilterCompiler
     * @param array{sql:string,expr:string,direction:string,field:?string,column:?string}|null $order
     * @return array{data:list<array<string,mixed>>,total:int,current_page:int,per_page:int}
     */
    public function paginatePublished(
        string $contentTypeUuid,
        string $locale,
        int $page,
        int $perPage,
        ?array $filter = null,
        ?array $order = null,
    ): array {
        $q = $this->base($contentTypeUuid, $locale);
        $this->applyFilter($q, $filter);

        $order ??= SortCompiler::defaultOrder();
        $q->orderByRaw(substr($order['sql'], strlen('ORDER BY ')));

        $result = $q->paginate($page, $perPage);

        /** @var list<array<string,mixed>> $data */
        $data = $result['data'] ?? [];
        return [
            'data' => array_map([$this, 'hydrate'], $data),
            'total' => (int) ($result['total'] ?? 0),
            'current_page' => (int) ($result['current_page'] ?? $page),
            'per_page' => (int) ($result['per_page'] ?? $perPage),
        ];
    }

    /**
     * One page of published (entry, route) pairs across ALL types/locales, for sitemap
     * generation. Joins the spine to entry_routes constrained to the publication's own
     * content type + locale, so an entry published in N locales yields N rows (one URL
     * each). Ordered stably (published_at DESC, entry_uuid ASC). `total` is the full count.
     *
     * Real LIMIT/OFFSET semantics — an arbitrary offset returns exactly that slice (the
     * sitemap builder pages with offset = (n-1) * PAGE_SIZE, but the contract must not
     * silently misbehave for a non-multiple offset).
     *
     * Fetched in ≤1000-row chunks so each SQL LIMIT stays under the framework query
     * validator's large-LIMIT guard (it warns above 1000), while still honouring the
     * sitemap protocol's 50 000-URL page size the caller requests.
     *
     * @return array{rows:list<array<string,mixed>>,total:int}
     */
    public function enumeratePublishedForSitemap(int $limit, int $offset = 0): array
    {
        $rows = $this->fetchChunked(
            fn (int $take, int $cursor): array => $this->db->table('entry_publications as p')
                ->join('entries as e', 'e.uuid', '=', 'p.entry_uuid')
                ->join('entry_routes as r', 'r.entry_uuid', '=', 'p.entry_uuid')
                ->join('content_types as ct', 'ct.uuid', '=', 'e.content_type_uuid')
                ->select(['p.entry_uuid', 'e.content_type_uuid', 'p.locale', 'r.slug', 'p.published_at'])
                ->where('e.status', '=', 'active')            // never enumerate archived/deleted
                ->where('ct.public_delivery', '=', true)      // sitemap is anonymous — public types only
                ->whereRaw('r.content_type_uuid = e.content_type_uuid')
                ->whereRaw('r.locale = p.locale')
                ->orderByRaw('p.published_at DESC, p.entry_uuid ASC, p.locale ASC')
                ->limit($take)
                ->offset($cursor)
                ->get(),
            $limit,
            $offset,
        );

        // Full count under the same joins/filters (a fresh builder — no select/limit/offset).
        $total = $this->db->table('entry_publications as p')
            ->join('entries as e', 'e.uuid', '=', 'p.entry_uuid')
            ->join('entry_routes as r', 'r.entry_uuid', '=', 'p.entry_uuid')
            ->join('content_types as ct', 'ct.uuid', '=', 'e.content_type_uuid')
            ->where('e.status', '=', 'active')
            ->where('ct.public_delivery', '=', true)
            ->whereRaw('r.content_type_uuid = e.content_type_uuid')
            ->whereRaw('r.locale = p.locale')
            ->count();

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * One page of published (entry, route, type, fields) rows across all/selected
     * types+locales, for search indexing. Joins the publication spine to the pinned
     * version, the entry (active guard), the entry_route (href slug), and the content
     * type (slug + public_delivery). Ordered by a TOTAL order (published_at DESC,
     * entry_uuid ASC, locale ASC) so LIMIT/OFFSET paging never skips or duplicates a
     * multi-locale entry's rows across page boundaries. No total count — callers page
     * until a short page, so a backfill costs zero COUNT queries.
     *
     * @return list<array<string,mixed>>
     */
    public function enumerateIndexable(
        int $limit,
        int $offset = 0,
        ?string $typeSlug = null,
        ?string $locale = null,
    ): array {
        return $this->fetchChunked(
            fn (int $take, int $cursor): array => $this
                ->applyIndexableJoins($this->db->table('entry_publications as p'), $typeSlug, $locale)
                ->select(self::INDEXABLE_SELECT)
                ->orderByRaw('p.published_at DESC, p.entry_uuid ASC, p.locale ASC')
                ->limit($take)
                ->offset($cursor)
                ->get(),
            $limit,
            $offset,
        );
    }

    /**
     * The single indexable row for one entry+locale (or null when not published, not
     * routed, archived, or its type is deleted) — the same join `enumerateIndexable()`
     * pages over, filtered to one publication pin. One query, delivery-parity guards.
     *
     * @return array<string,mixed>|null
     */
    public function findIndexableRow(string $entryUuid, string $locale): ?array
    {
        $rows = $this->applyIndexableJoins(
            $this->db->table('entry_publications as p'),
            typeSlug: null,
            locale: $locale,
            entryUuid: $entryUuid,
        )
            ->select(self::INDEXABLE_SELECT)
            ->limit(1)
            ->get();

        return $rows[0] ?? null;
    }

    private const INDEXABLE_SELECT = [
        'p.entry_uuid', 'e.content_type_uuid', 'ct.slug as content_type_slug',
        'ct.public_delivery', 'ct.mount_at_root', 'p.locale', 'r.slug', 'v.fields', 'p.published_at',
    ];

    /** The shared indexable join spine: publication → version, entry, route, content type. */
    private function applyIndexableJoins(
        QueryBuilder $q,
        ?string $typeSlug,
        ?string $locale,
        ?string $entryUuid = null,
    ): QueryBuilder {
        $q->join('entry_versions as v', 'v.uuid', '=', 'p.version_uuid')
            ->join('entries as e', 'e.uuid', '=', 'p.entry_uuid')
            ->join('entry_routes as r', 'r.entry_uuid', '=', 'p.entry_uuid')
            ->join('content_types as ct', 'ct.uuid', '=', 'e.content_type_uuid')
            ->where('e.status', '=', 'active')
            ->where('ct.status', '!=', 'deleted')
            ->whereRaw('r.content_type_uuid = e.content_type_uuid')
            ->whereRaw('r.locale = p.locale');
        if ($typeSlug !== null) {
            $q->where('ct.slug', '=', $typeSlug);
        }
        if ($locale !== null) {
            $q->where('p.locale', '=', $locale);
        }
        if ($entryUuid !== null) {
            $q->where('p.entry_uuid', '=', $entryUuid);
        }
        return $q;
    }

    /**
     * Fetch up to $limit rows in ≤1000-row chunks so each SQL LIMIT stays under the
     * framework query validator's large-LIMIT guard, while keeping real LIMIT/OFFSET
     * semantics for the caller. $fetch(take, cursor) returns one batch.
     *
     * @param callable(int,int):list<array<string,mixed>> $fetch
     * @return list<array<string,mixed>>
     */
    private function fetchChunked(callable $fetch, int $limit, int $offset): array
    {
        $chunkSize = 1000;
        $rows = [];
        $remaining = max(1, $limit);
        $cursor = max(0, $offset);

        while ($remaining > 0) {
            $take = min($chunkSize, $remaining);
            $batch = $fetch($take, $cursor);
            if ($batch === []) {
                break;
            }
            foreach ($batch as $row) {
                $rows[] = $row;
            }
            $got = count($batch);
            $cursor += $got;
            $remaining -= $got;
            if ($got < $take) {
                break; // exhausted the result set
            }
        }

        return $rows;
    }

    /**
     * Build the keyset cursor position for a row under a given order, so the caller
     * (controller) can emit the next-page cursor. Reads the sort value from the same
     * place the keyset predicate compares against: the JSONB field for a field sort,
     * or the `published_at` column for the default order. `id` is the monotonic
     * `v.id` tiebreaker.
     *
     * @param array<string,mixed> $row a hydrated row from listPublished()
     * @param array{sql:string,expr:string,direction:string,field:?string,column:?string} $order
     * @return array{sort:scalar|null,id:int}
     */
    public function cursorFor(array $row, array $order): array
    {
        if ($order['field'] !== null) {
            /** @var array<string,mixed> $fields */
            $fields = $row['fields'] ?? [];
            $sort = $fields[$order['field']] ?? null;
        } else {
            $sort = $row[$order['column'] ?? 'published_at'] ?? null;
        }

        return [
            'sort' => is_scalar($sort) ? $sort : null,
            'id' => (int) ($row['id'] ?? 0),
        ];
    }

    /**
     * @param array{sql:string,bindings:list<mixed>}|null $filter
     */
    private function applyFilter(QueryBuilder $q, ?array $filter): void
    {
        // Compiled filter from FilterCompiler (Task 4): a typed JSONB predicate over
        // entry_versions.fields, bound placeholders only. The predicate uses the same
        // expression as the filterable-field expression index so it hits the index.
        if ($filter !== null && ($filter['sql'] ?? '') !== '') {
            /** @var list<mixed> $bindings */
            $bindings = $filter['bindings'] ?? [];
            $q->whereRaw((string) $filter['sql'], $bindings);
        }
    }

    /** @return array<string,mixed>|null */
    public function findPublishedByRoute(string $contentTypeUuid, string $locale, string $slug): ?array
    {
        $row = $this->base($contentTypeUuid, $locale)
            ->join('entry_routes as r', 'r.entry_uuid', '=', 'p.entry_uuid')
            ->where('r.content_type_uuid', '=', $contentTypeUuid)
            ->where('r.locale', '=', $locale)
            ->where('r.slug', '=', $slug)
            ->first();
        return $row === null ? null : $this->hydrate($row);
    }

    /** @return array<string,mixed>|null */
    public function findPublishedByUuid(string $contentTypeUuid, string $locale, string $entryUuid): ?array
    {
        $row = $this->base($contentTypeUuid, $locale)->where('p.entry_uuid', '=', $entryUuid)->first();
        return $row === null ? null : $this->hydrate($row);
    }

    /**
     * Batch-load the published versions for a set of entry uuids (any type/locale) —
     * used by ReferenceResolver (Task 6). One query.
     *
     * Joins `entries` and filters `e.status = 'active'` so an archived-but-published
     * target is treated as gone (resolves to null), consistent with the main read
     * path's {@see base()} guard — a referenced entry that's been archived must not
     * surface through another entry's reference.
     *
     * Also joins `content_types` and drops any target whose type the request cannot read
     * ({@see DeliveryVisibility}): a reference must never expose a non-public type's fields
     * to a caller not scoped for it. `$grantedScopes` is required (null = anonymous) so
     * expansion cannot accidentally bypass the visibility gate.
     *
     * @param list<string>     $entryUuids
     * @param list<string>|null $grantedScopes null = anonymous (no api_key_scopes)
     * @return array<string,array<string,mixed>> keyed by entry_uuid
     */
    public function publishedByEntryUuids(array $entryUuids, string $locale, ?array $grantedScopes): array
    {
        if ($entryUuids === []) {
            return [];
        }
        $rows = $this->db->table('entry_publications as p')
            ->join('entry_versions as v', 'v.uuid', '=', 'p.version_uuid')
            ->join('entries as e', 'e.uuid', '=', 'p.entry_uuid')
            ->join('content_types as ct', 'ct.uuid', '=', 'e.content_type_uuid')
            ->select([
                'p.entry_uuid', 'v.uuid AS version_uuid', 'v.fields', 'v.version',
                'ct.slug AS _type_slug', 'ct.public_delivery AS _public',
            ])
            ->whereIn('p.entry_uuid', $entryUuids)
            ->where('e.status', '=', 'active')
            ->where('p.locale', '=', $locale)
            ->get();
        $out = [];
        foreach ($rows as $r) {
            $accessible = DeliveryVisibility::isAccessible(
                (bool) ($r['_public'] ?? false),
                (string) ($r['_type_slug'] ?? ''),
                $grantedScopes,
            );
            if (!$accessible) {
                continue; // caller can't read this target's type — resolves to null downstream
            }
            unset($r['_type_slug'], $r['_public']); // never surface the visibility metadata
            $out[$r['entry_uuid']] = $this->hydrate($r);
        }
        return $out;
    }

    /**
     * The distinct locales that have at least one PUBLISHED entry of a content type.
     *
     * Reads the publication spine (only published rows exist there) joined to `entries`
     * for the type filter + the active-status guard, so the result can never include a
     * locale that only exists as a draft. Used by `thallo:resync` to enumerate every
     * (type, locale) read scope through this leak-proof repository rather than touching
     * tables directly.
     *
     * @return list<string>
     */
    public function publishedLocalesForType(string $contentTypeUuid): array
    {
        $rows = $this->db->table('entry_publications as p')
            ->join('entries as e', 'e.uuid', '=', 'p.entry_uuid')
            ->select(['p.locale'])
            ->distinct()
            ->where('e.content_type_uuid', '=', $contentTypeUuid)
            ->where('e.status', '=', 'active')
            ->get();
        return array_values(array_map(static fn(array $r): string => (string) $r['locale'], $rows));
    }

    /**
     * The published pin(s) for a single entry, identity-only (entry uuid, content type,
     * locale, version) — used by `thallo:resync --entry={uuid}` to rebuild the publish
     * event without leaking field values or touching drafts.
     *
     * An entry may be published in several locales (one publication row per locale), so
     * this returns a list. Reads through the same leak-proof spine + active guard: a
     * draft-only or archived entry yields an empty list.
     *
     * @return list<array{entry:string,type:string,locale:string,version:int}>
     */
    public function publishedPinsForEntry(string $entryUuid): array
    {
        $rows = $this->db->table('entry_publications as p')
            ->join('entry_versions as v', 'v.uuid', '=', 'p.version_uuid')
            ->join('entries as e', 'e.uuid', '=', 'p.entry_uuid')
            ->select(['p.entry_uuid', 'e.content_type_uuid', 'p.locale', 'v.version'])
            ->where('p.entry_uuid', '=', $entryUuid)
            ->where('e.status', '=', 'active')
            ->get();

        return array_values(array_map(static fn(array $r): array => [
            'entry' => (string) $r['entry_uuid'],
            'type' => (string) $r['content_type_uuid'],
            'locale' => (string) $r['locale'],
            'version' => (int) $r['version'],
        ], $rows));
    }

    private function base(string $contentTypeUuid, string $locale): QueryBuilder
    {
        // entry_publications is the spine; join the pinned version. We also join
        // entries for the content-type filter and to never serve archived/deleted.
        return $this->db->table('entry_publications as p')
            ->join('entry_versions as v', 'v.uuid', '=', 'p.version_uuid')
            ->join('entries as e', 'e.uuid', '=', 'p.entry_uuid')
            ->select([
                'p.entry_uuid',
                'p.locale',
                'p.version_uuid',
                'p.published_at',
                'v.fields',
                'v.version',
                'v.schema_version',
                'v.id',
            ])
            ->where('e.content_type_uuid', '=', $contentTypeUuid)
            ->where('e.status', '=', 'active')   // never serve archived/deleted entries
            ->where('p.locale', '=', $locale);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrate(array $row): array
    {
        $row['fields'] = is_string($row['fields'] ?? null)
            ? (json_decode((string) $row['fields'], true) ?? [])
            : (array) ($row['fields'] ?? []);
        $row['version'] = (int) $row['version'];
        return $row;
    }
}

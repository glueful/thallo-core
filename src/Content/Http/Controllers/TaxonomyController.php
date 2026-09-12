<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\Controllers;

use Thallo\Core\Content\Delivery\Cursor;
use Thallo\Core\Content\Delivery\DeliveryItemShaper;
use Thallo\Core\Content\Delivery\DeliveryRepository;
use Thallo\Core\Content\Delivery\DeliveryVisibility;
use Thallo\Core\Content\Delivery\ExpandedTargets;
use Thallo\Core\Content\Delivery\FilterCompiler;
use Thallo\Core\Content\Delivery\InvalidFilterException;
use Thallo\Core\Content\Delivery\ReferenceResolver;
use Thallo\Core\Content\Delivery\SortCompiler;
use Thallo\Core\Content\Delivery\UnfilterableFieldException;
use Thallo\Core\Content\Http\Controllers\Concerns\HandlesDeliveryReads;
use Thallo\Core\Content\Http\DeliveryEtag;
use Thallo\Core\Content\Http\DTOs\Requests\Delivery\DeliveryFacetsQuery;
use Thallo\Core\Content\Http\DTOs\Requests\Delivery\DeliveryListQuery;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\PublishedReferenceRepository;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Content\Schema\FieldDefinition;
use Thallo\Core\Content\Schema\Migration\SchemaProjector;
use Thallo\Core\Content\Seo\CanonicalProjector;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\I18n\Contracts\LocaleManagerInterface;
use Glueful\Http\Response;
use Thallo\Contracts\Delivery\ReferenceTargetResolver;
use Glueful\Support\FieldSelection\FieldSelector;
use Glueful\Support\FieldSelection\Projector;
use Symfony\Component\HttpFoundation\Request;

/**
 * The taxonomy read surface (term-archives/facets spec): facet counts and term-archive
 * pages over the published-reference projection. Published-only, like everything on the
 * delivery spine; the projection — not JSONB containment — is the membership source.
 *
 * Visibility is FAIL-CLOSED for referenced target types (spec §4): whole-set term
 * enumeration is a disclosure surface, so a non-visible target type 404s the request.
 */
final class TaxonomyController
{
    use HandlesDeliveryReads;

    private const FACET_LIMIT_DEFAULT = 100;
    private const FACET_LIMIT_MAX = 500;

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly DeliveryRepository $delivery,
        private readonly ContentTypeRepository $types,
        private readonly PublishedReferenceRepository $projection,
        private readonly FilterCompiler $filters,
        private readonly SortCompiler $sorts,
        private readonly ReferenceResolver $references,
        private readonly ReferenceTargetResolver $terms,
        private readonly Projector $projector,
        private readonly DeliveryEtag $etags,
        private readonly LocaleManagerInterface $locales,
        private readonly CanonicalProjector $canonical,
        private readonly ?SchemaProjector $schemaProjector = null,
        private readonly ?DeliveryItemShaper $shaper = null,
    ) {
    }

    private function itemShaper(): DeliveryItemShaper
    {
        return $this->shaper ?? new DeliveryItemShaper(
            $this->types,
            $this->references,
            $this->projector,
            $this->canonical,
            $this->schemaProjector,
        );
    }

    /**
     * Global facet counts for filterable reference fields of {type} (spec §2):
     * `?fields=categories,tags` → data.{field} = [{uuid, slug, count}...], counts from the
     * projection joined to the target's publication in the request locale.
     */
    public function facets(Request $request, DeliveryFacetsQuery $query, string $type): Response
    {
        $typeRow = $this->types->findBySlug($type);
        if ($typeRow === null) {
            return Response::notFound('Content type not found.');
        }
        $schema = ContentTypeSchema::fromArray($typeRow['schema']);
        $typeUuid = (string) $typeRow['uuid'];
        $locale = $this->locale($query->locale);
        $scopes = $this->grantedScopes($request);

        $names = array_values(array_filter(array_map('trim', explode(',', $query->fields))));
        if ($names === []) {
            return Response::validation(['fields' => 'at least one field name is required']);
        }

        // Validate every field and gate every TARGET type BEFORE any counting — fail
        // closed, no partial per-field responses (spec §2/§4).
        /** @var array<string, array{field: FieldDefinition, target: array<string,mixed>|null}> $resolved */
        $resolved = [];
        foreach ($names as $name) {
            $field = $schema->field($name);
            if ($field === null || $field->type !== 'reference' || !$field->filterable) {
                return Response::validation([
                    'fields' => "field '{$name}' is not a filterable reference field of '{$type}'",
                ]);
            }
            $targetRow = $this->types->findBySlug((string) ($field->referenceType ?? ''));
            if ($targetRow !== null) {
                $visible = DeliveryVisibility::isAccessible(
                    (bool) $targetRow['public_delivery'],
                    (string) $targetRow['slug'],
                    $scopes,
                );
                if (!$visible) {
                    return Response::notFound('Not found.');
                }
            }
            $resolved[$name] = ['field' => $field, 'target' => $targetRow];
        }

        $limit = $this->facetLimit($query->limit);
        $data = [];
        $tagSlugs = [(string) $typeRow['slug']];
        foreach ($resolved as $name => $pair) {
            if ($pair['target'] === null) {
                $data[$name] = []; // unknown target type matches nothing (filter parity)
                continue;
            }
            $data[$name] = $this->projection->facetCounts(
                $typeUuid,
                $pair['field'],
                (string) $pair['target']['uuid'],
                $locale,
                $limit,
            );
            $tagSlugs[] = (string) $pair['target']['slug'];
        }

        $response = Response::success($data, 'Facet counts retrieved.');
        // The payload hash stands in for a version identity: counts have no single
        // version uuid, and any publish that changes them purges via the type tags.
        $etag = $this->etags->forItem(sha1((string) json_encode($data)), $this->selectionKey($request));
        $cacheTag = implode(', ', array_map(
            static fn(string $s): string => 'thallo:type:' . $s,
            array_values(array_unique($tagSlugs)),
        ));
        return $this->etags->applyHeaders($response, $etag, $this->ttl($typeRow), $cacheTag, $this->isScoped($request));
    }

    private function facetLimit(?int $limit): int
    {
        if ($limit === null || $limit < 1) {
            return self::FACET_LIMIT_DEFAULT;
        }
        return min($limit, self::FACET_LIMIT_MAX);
    }

    /**
     * Term archive (spec §3): the shaped term + its published members. Membership is
     * PINNED to the projection (an EXISTS combined into the compiled-filter slot) —
     * never filter[field][eq] recompiled through JSONB, or facets and archives could
     * diverge. Pagination/sort/field-selection/shaping/ETag delegate to the existing
     * delivery machinery; envelopes mirror DeliveryListQuery's two modes exactly.
     */
    public function archive(
        Request $request,
        DeliveryListQuery $query,
        string $type,
        string $field,
        string $term,
    ): Response {
        $typeRow = $this->types->findBySlug($type);
        if ($typeRow === null) {
            return Response::notFound('Content type not found.');
        }
        $schema = ContentTypeSchema::fromArray($typeRow['schema']);
        $typeUuid = (string) $typeRow['uuid'];
        $locale = $this->locale($query->locale);
        $scopes = $this->grantedScopes($request);

        $fieldDef = $schema->field($field);
        if ($fieldDef === null || $fieldDef->type !== 'reference' || !$fieldDef->filterable) {
            return Response::validation([
                'field' => "field '{$field}' is not a filterable reference field of '{$type}'",
            ]);
        }
        $targetRow = $this->types->findBySlug((string) ($fieldDef->referenceType ?? ''));
        if ($targetRow === null) {
            return Response::notFound('Term not found.');
        }
        $targetSlug = (string) $targetRow['slug'];
        if (!DeliveryVisibility::isAccessible((bool) $targetRow['public_delivery'], $targetSlug, $scopes)) {
            // Fail closed (spec §4): the term body would be in the envelope.
            return Response::notFound('Not found.');
        }

        // uuid-first then referenceSlugField, published-scoped — filter-value precedence.
        try {
            $targets = $this->terms->resolve($fieldDef, $locale, [$term]);
        } catch (InvalidFilterException $e) {
            return Response::validation(['term' => $e->getMessage()]);
        }
        $termUuid = $targets[0] ?? null;
        if ($termUuid === null) {
            return Response::notFound('Term not found.'); // ≠ empty archive (200)
        }
        $termRow = $this->delivery->findPublishedByUuid((string) $targetRow['uuid'], $locale, $termUuid);
        if ($termRow === null) {
            return Response::notFound('Term not found.');
        }
        // One collector spans the term and the member rows (spec §4).
        $expanded = new ExpandedTargets();
        $shapedTerm = $this->itemShaper()->shapePublic($termRow, (string) $targetRow['uuid'], $targetSlug, $expanded);

        try {
            $filter = $query->filter === [] ? null : $this->filters->compile($schema, $query->filter, $locale);
            $order = $this->sorts->compile($schema, $query->sort);
        } catch (UnfilterableFieldException | InvalidFilterException $e) {
            return Response::validation(['filter' => $e->getMessage()]);
        }
        $filter = $this->combineWithMembership(
            $filter,
            $this->projection->membershipPredicate($typeUuid, $field, $termUuid),
        );

        $selector = FieldSelector::fromRequest($request);

        if ($query->wantsPagination()) {
            [$page, $perPage] = $this->pageParams($query);
            $result = $this->delivery->paginatePublished($typeUuid, $locale, $page, $perPage, $filter, $order);
            $rows = $this->itemShaper()->shape(
                $result['data'],
                $schema,
                $selector,
                $locale,
                $typeUuid,
                $scopes,
                $expanded,
            );
            $totalPages = (int) ceil($result['total'] / max(1, $result['per_page']));
            // The list endpoint's flattened paginated envelope + ONE additive top-level
            // `term` (that envelope has no data object to nest into — spec §3).
            $response = new Response([
                'success' => true,
                'message' => 'Data retrieved successfully',
                'term' => $shapedTerm,
                'data' => array_map(fn(array $r): array => $this->itemShaper()->item($r), $rows),
                'current_page' => $result['current_page'],
                'per_page' => $result['per_page'],
                'total' => $result['total'],
                'total_pages' => $totalPages,
                'has_next_page' => $result['current_page'] < $totalPages,
                'has_previous_page' => $result['current_page'] > 1,
            ]);
            return $this->archiveCacheHeaders($request, $response, $rows, $typeRow, $termRow, $targetSlug, $expanded);
        }

        $limit = $this->limit($query);
        $cursor = Cursor::decode($query->cursor ?? '');
        $rows = $this->delivery->listPublished($typeUuid, $locale, $limit, $filter, $order, $cursor);
        $shaped = $this->itemShaper()->shape($rows, $schema, $selector, $locale, $typeUuid, $scopes, $expanded);
        $nextCursor = null;
        if (count($rows) === $limit && $rows !== []) {
            $nextCursor = Cursor::encode($this->delivery->cursorFor($rows[count($rows) - 1], $order));
        }
        $response = Response::success([
            'term' => $shapedTerm,
            'items' => array_map(fn(array $r): array => $this->itemShaper()->item($r), $shaped),
            'next_cursor' => $nextCursor,
        ], 'Content retrieved.');
        return $this->archiveCacheHeaders($request, $response, $shaped, $typeRow, $termRow, $targetSlug, $expanded);
    }

    /**
     * @param array{sql: string, bindings: list<mixed>}|null $filter
     * @param array{sql: string, bindings: list<mixed>} $membership
     * @return array{sql: string, bindings: list<mixed>}
     */
    private function combineWithMembership(?array $filter, array $membership): array
    {
        if ($filter === null) {
            return $membership;
        }
        return [
            'sql' => '(' . $filter['sql'] . ') AND ' . $membership['sql'],
            'bindings' => array_merge($filter['bindings'], $membership['bindings']),
        ];
    }

    /**
     * List ETag/Cache-Tag mechanics + the term's identity: the term's version is part of
     * the payload, and its entry tag + type tag make term edits/unpublishes purge the
     * archive with zero new invalidation code (spec §5).
     *
     * @param list<array<string,mixed>> $rows SHAPED member rows (still carrying envelope keys)
     * @param array<string,mixed> $typeRow
     * @param array<string,mixed> $termRow
     */
    private function archiveCacheHeaders(
        Request $request,
        Response $response,
        array $rows,
        array $typeRow,
        array $termRow,
        string $targetSlug,
        ExpandedTargets $expanded,
    ): Response {
        $versionUuids = array_map(static fn(array $r): string => (string) ($r['version_uuid'] ?? ''), $rows);
        $versionUuids[] = (string) ($termRow['version_uuid'] ?? '');
        $entryUuids = array_map(static fn(array $r): string => (string) ($r['entry_uuid'] ?? ''), $rows);
        $entryUuids[] = (string) ($termRow['entry_uuid'] ?? '');
        $etag = $this->etags->forList($versionUuids, $this->selectionKey($request), $expanded->versionIdentities());
        $cacheTag = $this->etags->cacheTag($entryUuids, (string) $typeRow['slug'], $expanded->entryUuids());
        if ($targetSlug !== (string) $typeRow['slug']) {
            $cacheTag .= ', thallo:type:' . $targetSlug; // self-referencing taxonomies dedupe
        }
        return $this->etags->applyHeaders($response, $etag, $this->ttl($typeRow), $cacheTag, $this->isScoped($request));
    }
}

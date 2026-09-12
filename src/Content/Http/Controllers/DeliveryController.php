<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\Controllers;

use Thallo\Core\Content\Delivery\Cursor;
use Thallo\Core\Content\Delivery\DeliveryItemShaper;
use Thallo\Core\Content\Delivery\DeliveryRepository;
use Thallo\Core\Content\Delivery\ExpandedTargets;
use Thallo\Core\Content\Delivery\FilterCompiler;
use Thallo\Core\Content\Delivery\ReferenceResolver;
use Thallo\Core\Content\Delivery\SortCompiler;
use Thallo\Core\Content\Delivery\UnfilterableFieldException;
use Thallo\Core\Content\Delivery\InvalidFilterException;
use Thallo\Core\Content\Http\DeliveryEtag;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Content\Schema\Migration\SchemaProjector;
use Thallo\Core\Content\Http\DTOs\Requests\Delivery\DeliveryListQuery;
use Thallo\Core\Content\Http\DTOs\Requests\Delivery\DeliveryShowQuery;
use Thallo\Core\Content\Http\DTOs\Responses\Delivery\DeliveryItemData;
use Thallo\Core\Content\Http\DTOs\Responses\Delivery\DeliveryListData;
use Thallo\Core\Content\Http\DTOs\Responses\Delivery\DeliveryShowItemData;
use Thallo\Core\Http\DTOs\ErrorResponse;
use Thallo\Core\Content\Seo\CanonicalProjector;
use Thallo\Core\Content\Seo\RouteResolver;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\I18n\Contracts\LocaleManagerInterface;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Support\FieldSelection\FieldSelector;
use Glueful\Support\FieldSelection\Projector;
use Symfony\Component\HttpFoundation\Request;

/**
 * The public delivery API — serves ONLY published content.
 *
 * Every read goes through {@see DeliveryRepository}, whose queries join the publication
 * spine so drafts/unpublished/archived entries physically cannot leak. Both list paths
 * (cursor/keyset by default, framework-offset when `?page`/`?perPage` is present) apply
 * the same {@see FilterCompiler}/{@see SortCompiler}, resolve references via
 * {@see ReferenceResolver}, shape output through the framework {@see Projector} against a
 * schema-derived allow-list, and emit ETag/Cache-Control/Cache-Tag validators via
 * {@see DeliveryEtag}.
 */
final class DeliveryController
{
    use Concerns\HandlesDeliveryReads;

    /**
     * @param DeliveryRepository    $delivery publication-spine reads (published-only)
     * @param ContentTypeRepository $types    resolves the {type} slug to its schema
     * @param FilterCompiler        $filters  compiles `filter[...]` against filterable fields
     * @param SortCompiler          $sorts    compiles `sort` against filterable fields
     * @param ReferenceResolver     $references batch-expands reference/asset fields (no N+1)
     * @param Projector             $projector applies `fields`/`expand` to the `fields` object
     * @param DeliveryEtag          $etags    ETag/Cache-Control/Cache-Tag validators
     */
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly DeliveryRepository $delivery,
        private readonly ContentTypeRepository $types,
        private readonly FilterCompiler $filters,
        private readonly SortCompiler $sorts,
        private readonly ReferenceResolver $references,
        private readonly Projector $projector,
        private readonly DeliveryEtag $etags,
        private readonly LocaleManagerInterface $locales,
        private readonly RouteResolver $resolver,
        private readonly CanonicalProjector $canonical,
        private readonly ?SchemaProjector $schemaProjector = null,
        private readonly ?DeliveryItemShaper $shaper = null,
    ) {
    }

    private function itemShaper(): DeliveryItemShaper
    {
        // Shaping lives in DeliveryItemShaper so PublicRouteResolver serves the identical
        // shape; nullable + lazily built only to keep this constructor autowire-compatible.
        return $this->shaper ?? new DeliveryItemShaper(
            $this->types,
            $this->references,
            $this->projector,
            $this->canonical,
            $this->schemaProjector,
        );
    }

    /**
     * List published entries of the {type} content type. Resolves the slug (404 if unknown),
     * compiles filter+sort against the schema (a non-filterable field or bad operator → 422),
     * then branches on pagination mode: explicit `?page`/`?perPage` uses the framework offset
     * envelope, otherwise a keyset cursor list returning `items` + `next_cursor`. Either way
     * rows are shaped (reference-expanded + field-projected) and decorated with cache headers.
     *
     * @param string $type Content type slug
     */
    #[ApiOperation(
        summary: 'List published entries of a content type',
        description: 'Published entries only. Cursor pagination by default; `page`/`perPage` switches to '
            . 'offset. Filter and sort are accepted only on filterable fields.',
        tags: ['Thallo Delivery'],
    )]
    #[ApiResponse(
        200,
        schema: DeliveryListData::class,
        description: 'A page of published entries (cursor mode by default; offset mode replaces `data` '
            . 'with the item array plus top-level pagination keys).',
    )]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'Unknown content type slug.')]
    #[ApiResponse(
        422,
        schema: ErrorResponse::class,
        envelope: false,
        description: 'Filter or sort references a non-filterable field or an unsupported operator.',
    )]
    // 401/403/429/500 are inferred from middleware + documentation.errors config (ErrorResponse body).
    public function index(Request $request, DeliveryListQuery $query, string $type): Response
    {
        $typeRow = $this->types->findBySlug($type);
        if ($typeRow === null) {
            return Response::notFound('Content type not found.');
        }
        $schema = ContentTypeSchema::fromArray($typeRow['schema']);
        $typeUuid = (string) $typeRow['uuid'];
        $locale = $this->locale($query->locale);

        try {
            $filter = $this->compileFilter($query->filter, $schema, $locale);
            $order = $this->sorts->compile($schema, $query->sort);
        } catch (UnfilterableFieldException | InvalidFilterException $e) {
            return Response::validation(['filter' => $e->getMessage()]);
        }

        $selector = FieldSelector::fromRequest($request);
        $scopes = $this->grantedScopes($request);
        $expanded = new ExpandedTargets();

        // Pagination branch: explicit ?page/?perPage uses the framework offset envelope.
        if ($query->wantsPagination()) {
            [$page, $perPage] = $this->pageParams($query);
            $result = $this->delivery->paginatePublished($typeUuid, $locale, $page, $perPage, $filter, $order);
            $rows = $this->shape($result['data'], $schema, $selector, $locale, $typeUuid, $scopes, $expanded);
            $response = Response::paginated(
                array_map(fn(array $r): array => $this->item($r), $rows),
                $result['total'],
                $result['current_page'],
                $result['per_page'],
            );
            return $this->withCacheHeaders($request, $response, $rows, $typeRow, $expanded);
        }

        // Default: cursor/keyset list.
        $limit = $this->limit($query);
        $cursor = Cursor::decode($query->cursor ?? '');
        $rows = $this->delivery->listPublished($typeUuid, $locale, $limit, $filter, $order, $cursor);
        $shaped = $this->shape($rows, $schema, $selector, $locale, $typeUuid, $scopes, $expanded);

        $nextCursor = null;
        if (count($rows) === $limit && $rows !== []) {
            $nextCursor = Cursor::encode($this->delivery->cursorFor($rows[count($rows) - 1], $order));
        }

        $response = Response::success([
            'items' => array_map(fn(array $r): array => $this->item($r), $shaped),
            'next_cursor' => $nextCursor,
        ], 'Content retrieved.');

        return $this->withCacheHeaders($request, $response, $shaped, $typeRow, $expanded);
    }

    /**
     * Return one published entry of {type}, resolved by route slug first and falling back to a
     * UUID lookup only when the value looks like a 12-char nanoid. A draft-only/unpublished
     * target yields 404 (the publication spine hides it). Emits an ETag keyed to the published
     * version + selection; a matching `If-None-Match` short-circuits to 304 Not Modified.
     *
     * @param string $type       Content type slug
     * @param string $slugOrUuid Route slug, or the entry's 12-char UUID
     */
    #[ApiOperation(
        summary: 'Get a single published entry by slug or UUID',
        description: 'Resolved by route slug or 12-char entry UUID; published only (draft/unpublished → 404). '
            . 'Supports `If-None-Match` → 304.',
        tags: ['Thallo Delivery'],
    )]
    #[ApiResponse(200, schema: DeliveryShowItemData::class, description: 'The published entry with SEO metadata.')]
    #[ApiResponse(
        304,
        description: 'Not Modified — the supplied If-None-Match ETag still matches the published version.',
    )]
    #[ApiResponse(
        404,
        schema: ErrorResponse::class,
        envelope: false,
        description: 'Unknown content type, or no published entry for the given slug/UUID.',
    )]
    // 401/403/429/500 are inferred from middleware + documentation.errors config (ErrorResponse body).
    public function show(Request $request, DeliveryShowQuery $query, string $type, string $slugOrUuid): Response
    {
        $typeRow = $this->types->findBySlug($type);
        if ($typeRow === null) {
            return Response::notFound('Content type not found.');
        }
        $schema = ContentTypeSchema::fromArray($typeRow['schema']);
        $typeUuid = (string) $typeRow['uuid'];
        $locales = $this->localeChain($query->locale);

        $result = $this->resolver->resolve($typeUuid, $type, $locales, $slugOrUuid);
        if ($result === null || $result->isGone()) {
            return Response::notFound('Content not found.');
        }
        if ($result->isRedirect()) {
            return $this->redirectResponse($request, $result->redirect(), $type);
        }

        $row = $result->content();

        $selector = FieldSelector::fromRequest($request);
        // shape() runs BEFORE the conditional check, so the collector is populated
        // on the 304 path too — the validator must fold in expansion identities
        // even when no body is built (spec §4 P1).
        $expanded = new ExpandedTargets();
        $shaped = $this->shape(
            [$row],
            $schema,
            $selector,
            (string) $row['locale'],
            $typeUuid,
            $this->grantedScopes($request),
            $expanded,
        );
        $item = $this->item($shaped[0]);
        $item['seo'] = $this->canonical->project(
            (string) $row['entry_uuid'],
            $typeUuid,
            $type,
            (string) $row['locale'],
        );

        $private = $this->isScoped($request);
        $etag = $this->etags->forItem(
            (string) $row['version_uuid'],
            $this->selectionKey($request),
            $expanded->versionIdentities(),
        );
        if ($this->etags->matches($request, $etag)) {
            return $this->etags->notModified(
                $etag,
                $this->ttl($typeRow),
                $this->etags->cacheTag([(string) $row['entry_uuid']], $type, $expanded->entryUuids()),
                $private,
            );
        }

        $response = Response::success($item, 'Content retrieved.');
        return $this->etags->applyHeaders(
            $response,
            $etag,
            $this->ttl($typeRow),
            $this->etags->cacheTag([(string) $row['entry_uuid']], $type, $expanded->entryUuids()),
            $private,
        );
    }

    /**
     * @param array<string,mixed> $descriptor
     */
    private function redirectResponse(Request $request, array $descriptor, string $typeSlug): Response
    {
        $public = $this->publicRedirectDescriptor($descriptor);
        $etag = '"' . sha1((string) json_encode($descriptor, JSON_THROW_ON_ERROR)) . '"';
        if ($this->etags->matches($request, $etag)) {
            return $this->etags->notModified(
                $etag,
                $this->redirectTtl(),
                $this->redirectCacheTag($descriptor, $typeSlug)
            );
        }

        return $this->etags->applyHeaders(
            Response::success(['redirect' => $public], 'Redirect resolved.'),
            $etag,
            $this->redirectTtl(),
            $this->redirectCacheTag($descriptor, $typeSlug),
        );
    }

    /** @param array<string,mixed> $descriptor */
    private function redirectCacheTag(array $descriptor, string $typeSlug): string
    {
        $entries = isset($descriptor['_target_entry_uuid']) ? [(string) $descriptor['_target_entry_uuid']] : [];
        return $this->etags->cacheTag($entries, $typeSlug);
    }

    /** @param array<string,mixed> $descriptor */
    private function publicRedirectDescriptor(array $descriptor): array
    {
        return array_filter(
            $descriptor,
            static fn (string $key): bool => !str_starts_with($key, '_'),
            ARRAY_FILTER_USE_KEY
        );
    }

    private function redirectTtl(): int
    {
        return max(0, (int) config($this->context, 'thallo.seo.redirect_ttl', 60));
    }

    /**
     * Resolve references then project each row's schema fields against the schema-derived
     * allow-list. The envelope keys (uuid/version/published_at) are not projectable — only
     * the `fields` sub-object honours `?fields`.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function shape(
        array $rows,
        ContentTypeSchema $schema,
        FieldSelector $selector,
        string $locale,
        string $typeUuid,
        ?array $grantedScopes,
        ?ExpandedTargets $expanded = null,
    ): array {
        return $this->itemShaper()->shape($rows, $schema, $selector, $locale, $typeUuid, $grantedScopes, $expanded);
    }

    /**
     * The public envelope for one hydrated row.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function item(array $row): array
    {
        return $this->itemShaper()->item($row);
    }

    /**
     * @param list<array<string,mixed>> $rows the SHAPED rows (still carrying envelope keys)
     */
    private function withCacheHeaders(
        Request $request,
        Response $response,
        array $rows,
        array $typeRow,
        ExpandedTargets $expanded,
    ): Response {
        $versionUuids = array_map(static fn(array $r): string => (string) ($r['version_uuid'] ?? ''), $rows);
        $entryUuids = array_map(static fn(array $r): string => (string) ($r['entry_uuid'] ?? ''), $rows);
        $etag = $this->etags->forList($versionUuids, $this->selectionKey($request), $expanded->versionIdentities());
        $typeSlug = (string) $typeRow['slug'];

        return $this->etags->applyHeaders(
            $response,
            $etag,
            $this->ttl($typeRow),
            $this->etags->cacheTag($entryUuids, $typeSlug, $expanded->entryUuids()),
            $this->isScoped($request),
        );
    }

    /**
     * @param array<string,mixed> $filter the raw bracket-array filter from the request DTO
     * @return array{sql:string,bindings:list<mixed>}|null
     */
    private function compileFilter(array $filter, ContentTypeSchema $schema, string $locale): ?array
    {
        if ($filter === []) {
            return null;
        }
        return $this->filters->compile($schema, $filter, $locale);
    }

    /**
     * Requested locale plus configured i18n fallbacks, de-duplicated and never empty.
     *
     * @return non-empty-list<string>
     */
    private function localeChain(?string $locale): array
    {
        $requested = $this->locale($locale);
        $chain = $this->locales->fallbackChain($requested);
        array_unshift($chain, $requested);
        $out = [];
        foreach ($chain as $locale) {
            $locale = trim((string) $locale);
            if ($locale === '') {
                continue;
            }
            $out[$locale] = $locale;
        }

        return $out === [] ? [$requested] : array_values($out);
    }
}

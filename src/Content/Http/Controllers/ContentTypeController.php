<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\Controllers;

use Thallo\Core\Content\Events\ModelCreated;
use Thallo\Core\Content\Events\ModelDeleted;
use Thallo\Core\Content\Events\ModelUpdated;
use Thallo\Core\Content\Indexing\EnsureFilterIndexesJob;
use Thallo\Core\Content\Indexing\FilterIndexJobDispatcher;
use Thallo\Core\Content\Pipeline\PublishEventEmitter;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Routing\RootMountGuard;
use Thallo\Core\Content\Http\DTOs\CreateContentTypeData;
use Thallo\Core\Content\Http\DTOs\FieldDefinitionData;
use Thallo\Core\Content\Http\DTOs\UpdateContentTypeData;
use Thallo\Core\Content\Http\DTOs\UpdateContentTypeSchemaData;
use Thallo\Core\Content\Schema\SchemaParseException;
use Thallo\Core\Content\Http\DTOs\Responses\ContentTypes\ContentTypeListData;
use Thallo\Core\Content\Http\DTOs\Responses\ContentTypes\ContentTypeResultData;
use Thallo\Core\Http\DTOs\ErrorResponse;
use Thallo\Core\Support\ActorHelper;
use Glueful\Auth\UserIdentity;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The admin API for content types (models) — the schema-definition CRUD surface that the
 * entry and delivery layers read from. Persistence and schema parsing live in
 * {@see ContentTypeRepository}; this controller validates request input, maps a
 * {@see SchemaParseException} to a 422, and emits {@see ModelCreated}/{@see ModelUpdated}
 * after commit.
 *
 * Writes that change the filterable-field set don't build indexes inline: both store() and
 * updateSchema() enqueue an {@see EnsureFilterIndexesJob} so the DDL runs out-of-band. Read
 * routes require `content.view`; mutating routes require `content.manage`
 * (enforced by route middleware, surfacing as 401/403 before these methods run).
 */
final class ContentTypeController
{
    public function __construct(
        private readonly ContentTypeRepository $types,
        private readonly FilterIndexJobDispatcher $filterIndexes,
        private readonly ?PublishEventEmitter $events = null,
        private readonly ?\Glueful\Cache\CacheStore $cache = null,
        /** Root URL namespace guard; null = ungated (tests, minimal wiring). */
        private readonly ?RootMountGuard $rootGuard = null,
    ) {
    }

    /**
     * List every content type defined in this Thallo instance, including each one's field
     * schema, straight from {@see ContentTypeRepository::all()}.
     */
    #[ApiOperation(
        summary: 'List content types',
        description: 'Each item includes its full field schema.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, schema: ContentTypeListData::class, description: 'All content types.')]
    // 401/403/429/500 inferred from middleware + documentation.errors config.
    public function index(Request $request): Response
    {
        return Response::success(['content_types' => $this->types->all()], 'Content types retrieved.');
    }

    /**
     * Create a content type from the request body. Guards the slug (lowercase identifier,
     * 1–160 chars) and a non-empty name up front, then rejects a duplicate slug — both as
     * 422 — before persisting. An invalid field schema surfaces as a caught
     * {@see SchemaParseException} (also 422). On success the filter-index reconciliation is
     * enqueued and a {@see ModelCreated} event is emitted after commit.
     */
    #[ApiOperation(
        summary: 'Create a content type',
        description: '`slug` must be a unique lowercase identifier. Filterable-field indexes are built '
            . 'out-of-band after commit.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(201, schema: ContentTypeResultData::class, description: 'Content type created.')]
    #[ApiResponse(
        422,
        schema: ErrorResponse::class,
        envelope: false,
        description: 'Invalid slug/name, duplicate slug, or invalid field schema.',
    )]
    // 401/403/429/500 inferred from middleware + documentation.errors config.
    public function store(CreateContentTypeData $input, Request $request): Response
    {
        // Structural validation (slug shape, required name, well-formed field defs) is done
        // by the hydrated DTO. The duplicate-slug check and semantic schema parsing
        // (SchemaParseException) are domain rules and stay here.
        if ($this->types->findBySlug($input->slug) !== null) {
            return Response::validation(['slug' => "content type '{$input->slug}' already exists"]);
        }
        // Type paths take precedence at resolve time, so a new type slug must
        // not shadow a live root-mounted page (root-mounted-types spec §3).
        if ($this->rootGuard !== null && ($conflicts = $this->rootGuard->typeSlugConflicts($input->slug)) !== []) {
            return Response::validation(['slug' => implode('; ', $conflicts)]);
        }
        try {
            $uuid = $this->types->create([
                'slug' => $input->slug,
                'name' => trim($input->name),
                'description' => $input->description,
                'cache_ttl' => $input->cache_ttl,
                'public_delivery' => $input->public_delivery,
                'mount_at_root' => $input->mount_at_root,
                'schema' => array_map(static fn (FieldDefinitionData $f): array => $f->toArray(), $input->schema),
                'created_by' => $this->actor($request),
            ]);
        } catch (SchemaParseException $e) {
            return Response::validation(['schema' => $e->getMessage()]);
        }
        $this->ensureFilterIndexes($uuid);
        $this->events?->emitAfterCommit(new ModelCreated(type: $input->slug, actor: $this->actor($request)));
        return Response::created(['content_type' => $this->types->findByUuid($uuid)], 'Content type created.');
    }

    /**
     * Return one content type (with its field schema) by slug, or 404 if no such slug exists.
     *
     * @param string $slug Content type slug
     */
    #[ApiOperation(
        summary: 'Get a content type by slug',
        description: 'Includes the full field schema.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, schema: ContentTypeResultData::class, description: 'The content type.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'No content type with that slug.')]
    // 401/403/429/500 inferred from middleware + documentation.errors config.
    public function show(Request $request, string $slug): Response
    {
        $row = $this->types->findBySlug($slug);
        return $row === null
            ? Response::notFound('Content type not found.')
            : Response::success(['content_type' => $row], 'Content type retrieved.');
    }

    /**
     * Replace the field schema of an existing content type (looked up by slug; 404 if absent).
     * Delegates to {@see ContentTypeRepository::updateSchema()}, which bumps the schema version;
     * a malformed schema is caught as a {@see SchemaParseException} and returned as 422. On
     * success the filter-index reconciliation is re-enqueued and a {@see ModelUpdated} event is
     * emitted after commit.
     *
     * @param string $slug Content type slug
     */
    #[ApiOperation(
        summary: 'Update a content type\'s field schema',
        description: 'Replaces the schema wholesale (not a merge) and bumps the schema version. '
            . 'Filterable-field indexes are rebuilt out-of-band after commit.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, schema: ContentTypeResultData::class, description: 'Schema updated.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'No content type with that slug.')]
    #[ApiResponse(422, schema: ErrorResponse::class, envelope: false, description: 'Invalid field schema.')]
    // 401/403/429/500 inferred from middleware + documentation.errors config.
    public function updateSchema(UpdateContentTypeSchemaData $input, Request $request, string $slug): Response
    {
        $row = $this->types->findBySlug($slug);
        if ($row === null) {
            return Response::notFound('Content type not found.');
        }
        try {
            $this->types->updateSchema(
                $row['uuid'],
                array_map(static fn (FieldDefinitionData $f): array => $f->toArray(), $input->schema),
            );
        } catch (SchemaParseException $e) {
            return Response::validation(['schema' => $e->getMessage()]);
        }
        $this->ensureFilterIndexes($row['uuid']);
        $this->events?->emitAfterCommit(new ModelUpdated(type: (string) $row['slug'], actor: $this->actor($request)));
        return Response::success(['content_type' => $this->types->findByUuid($row['uuid'])], 'Schema updated.');
    }

    /**
     * Update NON-SCHEMA metadata: name, description, cache_ttl, and — the
     * headline — `public_delivery`, which was previously creation-only,
     * leaving no path to make an existing type publicly deliverable. The
     * slug is immutable; schema edits keep their own endpoint. A
     * public_delivery change purges the render page cache: flipping OFF
     * must stop cached live pages from serving, and ON must clear cached
     * 404 lookups.
     */
    #[ApiOperation(
        summary: 'Update content-type metadata',
        description: 'Non-schema metadata only (slug immutable; schema has its own endpoint). '
            . 'Changing public_delivery or mount_at_root purges the render page cache.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, schema: ContentTypeResultData::class, description: 'Content type updated.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'No content type with that slug.')]
    #[ApiResponse(422, schema: ErrorResponse::class, envelope: false, description: 'Invalid value (empty name).')]
    public function update(UpdateContentTypeData $input, Request $request, string $slug): Response
    {
        $row = $this->types->findBySlug($slug);
        if ($row === null) {
            return Response::notFound('Content type not found.');
        }
        if ($input->name !== null && trim($input->name) === '') {
            return Response::validation(['name' => 'must not be empty']);
        }
        // Flipping mount_at_root ON admits every existing route + redirect
        // source of this type into the global root namespace — validate them
        // ALL first; any conflict rejects the flip wholesale (spec §3).
        $flippingOn = $input->mount_at_root === true && (bool) ($row['mount_at_root'] ?? false) === false;
        if ($flippingOn && $this->rootGuard !== null) {
            $conflicts = $this->rootGuard->conflictsForType((string) $row['uuid']);
            if ($conflicts !== []) {
                return Response::error(
                    'Cannot mount at root: ' . implode('; ', $conflicts),
                    Response::HTTP_CONFLICT,
                    ['code' => 'ROOT_MOUNT_CONFLICT', 'conflicts' => $conflicts]
                );
            }
        }
        // Both flags change what URLs resolve (visibility / grammar), so either
        // flip purges the render page cache: stale 200s AND cached 404s.
        $routingChanged = ($input->public_delivery !== null
                && $input->public_delivery !== (bool) ($row['public_delivery'] ?? false))
            || ($input->mount_at_root !== null
                && $input->mount_at_root !== (bool) ($row['mount_at_root'] ?? false));
        $this->types->updateMeta((string) $row['uuid'], [
            'name' => $input->name,
            'description' => $input->description,
            'cache_ttl' => $input->cache_ttl,
            'public_delivery' => $input->public_delivery,
            'mount_at_root' => $input->mount_at_root,
        ]);
        if ($routingChanged) {
            $this->cache?->deletePattern('render:*');
            $this->cache?->deletePattern('tenant:*:render:*');
        }
        $this->events?->emitAfterCommit(new ModelUpdated(type: (string) $row['slug'], actor: $this->actor($request)));
        return Response::success(
            ['content_type' => $this->types->findByUuid((string) $row['uuid'])],
            'Content type updated.',
        );
    }

    /**
     * Soft-delete a content type. Existing entries remain in storage, but the model is hidden
     * from admin listing and delivery lookups.
     *
     * @param string $slug Content type slug
     */
    #[ApiOperation(
        summary: 'Delete a content type',
        description: 'Soft-delete: existing entries stay in storage but the model is hidden from listing '
            . 'and delivery.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, description: 'Content type deleted.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'No content type with that slug.')]
    public function destroy(Request $request, string $slug): Response
    {
        $row = $this->types->findBySlug($slug);
        if ($row === null) {
            return Response::notFound('Content type not found.');
        }
        $this->types->softDelete((string) $row['uuid']);
        $this->events?->emitAfterCommit(new ModelDeleted(type: (string) $row['slug'], actor: $this->actor($request)));
        return Response::success([], 'Content type deleted.');
    }

    /**
     * Enqueue the expression-index reconciliation for a content type's filterable fields.
     * The DDL (CREATE/DROP INDEX CONCURRENTLY) runs out-of-band in the queued job.
     */
    private function ensureFilterIndexes(string $typeUuid): void
    {
        $this->filterIndexes->dispatch($typeUuid);
    }

    /** @return array<string,mixed> */
    private function body(Request $request): array
    {
        $data = json_decode((string) $request->getContent(), true);
        return is_array($data) ? $data : [];
    }

    /**
     * The authenticated principal's id for audit attribution (created_by / event actor),
     * or null when the request carries no resolved {@see UserIdentity}.
     */
    private function actor(Request $request): ?string
    {
        return ActorHelper::uuidFromRequest($request);
    }
}

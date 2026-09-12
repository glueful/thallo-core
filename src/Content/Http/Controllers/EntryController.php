<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\Controllers;

use Thallo\Core\Content\Blocks\BlockMigrationGate;
use Thallo\Core\Content\Blocks\Migration\BlockMigrationInProgressException;
use Thallo\Core\Content\Http\DTOs\CreateEntryData;
use Thallo\Core\Content\Http\DTOs\AssignRouteData;
use Thallo\Core\Content\Http\DTOs\Requests\EntryListQuery;
use Thallo\Core\Content\Http\DTOs\Responses\Entries\EntryListData;
use Thallo\Core\Content\Http\DTOs\ApplyPreviewData;
use Thallo\Core\Content\Http\DTOs\CopyLocaleData;
use Thallo\Core\Content\Http\DTOs\SaveDraftData;
use Thallo\Core\Content\Http\DTOs\Responses\Entries\DraftResultData;
use Thallo\Core\Content\Http\DTOs\Responses\Entries\EntryCreateResultData;
use Thallo\Core\Content\Http\DTOs\Responses\Entries\EntryLocalesResultData;
use Thallo\Core\Content\Http\DTOs\Responses\Entries\EntryResultData;
use Thallo\Core\Content\Localization\ContentLocaleService;
use Thallo\Core\Content\Preview\PreviewToken;
use Thallo\Core\Content\Preview\PreviewTokenException;
use Thallo\Core\Content\Preview\PreviewWorkingCopyStore;
use Thallo\Core\Content\Preview\ResolvesPreviewKey;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Repositories\ReferenceProjectionRepository;
use Thallo\Core\Content\Repositories\RouteRepository;
use Thallo\Core\Content\Routing\RootMountGuard;
use Thallo\Core\Content\Schema\Migration\SchemaProjector;
use Thallo\Core\Content\Support\OptimisticLockException;
use Thallo\Core\Content\Validation\FieldValidator;
use Thallo\Core\Content\Validation\ValidationException;
use Thallo\Core\Http\DTOs\ErrorResponse;
use Thallo\Core\Settings\GeneralSettings;
use Thallo\Core\Support\ActorHelper;
use Glueful\Auth\UserIdentity;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The admin API for entries and their working drafts — create an entry, read its identity
 * record, and read/save the per-locale draft. Identity and draft persistence (including the
 * optimistic-lock CAS and the reference projection) live in {@see EntryRepository}; field
 * validation against the content type schema is delegated to {@see FieldValidator}.
 *
 * The interesting path is {@see EntryController::saveDraft()}: it validates first (a
 * {@see ValidationException} → 422) and then catches the repository's
 * {@see OptimisticLockException} to return a 409 with the current draft so the client can
 * rebase. Routes are permission-gated (`content.view` / `content.create` / `content.edit`) by
 * middleware, so authz failures surface as 401/403 before these methods run.
 */
final class EntryController
{
    use ResolvesPreviewKey;

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly EntryRepository $entries,
        private readonly ContentTypeRepository $types,
        private readonly FieldValidator $validator,
        private readonly RouteRepository $routes,
        private readonly ReferenceProjectionRepository $references,
        private readonly ContentLocaleService $locales,
        private readonly ?SchemaProjector $schemaProjector = null,
        /** Block-migration write gate (spec §3); null = ungated (tests, minimal wiring). */
        private readonly ?BlockMigrationGate $gate = null,
        /** Loop C working-copy stash; null = apply unavailable (minimal wiring). */
        private readonly ?PreviewWorkingCopyStore $workingCopies = null,
        /** Root URL namespace guard; null = ungated (tests, minimal wiring). */
        private readonly ?RootMountGuard $rootGuard = null,
    ) {
    }

    /**
     * Draft-inclusive admin list of entries for a content type (`?type={slug}`). Unlike the
     * public delivery list, this includes drafts/scheduled/unpublished entries — it reads the
     * `entries` identity table, not the publication spine. Each row carries a derived display
     * title, the coarse editorial status, the locales present, and updated_at. Offset paged
     * (`?page`/`?perPage`, perPage clamped); optional `?q=` filters on the display title.
     */
    #[ApiOperation(
        summary: 'List entries of a content type (draft-inclusive)',
        description: 'Returns a page of entries for the content type named by `type` (slug), INCLUDING '
            . 'drafts/scheduled/unpublished entries (this is the admin authoring list, not the published '
            . 'delivery feed). Each row has a derived `display_title`, editorial `status` '
            . '(draft|scheduled|published), the `locales` present, and `updated_at`. Offset paged via '
            . '`page`/`perPage`; `q` filters on the display title. Requires the `content.view` permission.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, schema: EntryListData::class, description: 'A page of entries.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'Unknown content type slug.')]
    #[ApiResponse(422, schema: ErrorResponse::class, envelope: false, description: 'Missing/invalid `type`.')]
    // 401/403/429/500 inferred from middleware + documentation.errors config.
    // The query params (type/q/page/perPage) are documented + validated by EntryListQuery.
    public function index(EntryListQuery $query): Response
    {
        // `type` is guaranteed present + a string by the DTO's required rule (else 422 at hydration).
        $typeRow = $this->types->findBySlug($query->type);
        if ($typeRow === null) {
            return Response::notFound('Content type not found.');
        }

        $page = max(1, $query->page ?? 1);
        $settings = app($this->context, GeneralSettings::class);
        $max = $settings->maxPerPage();
        $default = $settings->defaultPerPage();
        $perPage = $query->perPage ?? $default;
        $perPage = $perPage < 1 ? $default : min($perPage, $max);

        $result = $this->entries->listForType(
            (string) $typeRow['uuid'],
            $this->locales->default(),
            $page,
            $perPage,
            ($query->q !== null && $query->q !== '') ? $query->q : null,
        );

        return Response::success($result, 'Entries retrieved.');
    }

    /**
     * Create a new entry of the content type named by `content_type` (slug; unknown → 422),
     * seeding an empty draft in the requested locale (defaulting to the i18n default locale).
     * Returns the fresh entry identity record plus the empty draft.
     */
    #[ApiOperation(
        summary: 'Create an entry',
        description: 'Seeds an empty draft in the given `locale` (defaults to the i18n default).',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(201, schema: EntryCreateResultData::class, description: 'Entry created with an empty draft.')]
    #[ApiResponse(422, schema: ErrorResponse::class, envelope: false, description: 'Unknown content type.')]
    // 401/403/429/500 inferred from middleware + documentation.errors config.
    public function store(CreateEntryData $input, Request $request): Response
    {
        // Structural validation (content_type/locale are strings) is done by the hydrated DTO.
        // The content-type-exists check is a domain rule and stays here.
        $type = $this->types->findBySlug($input->content_type);
        if ($type === null) {
            return Response::validation(['content_type' => 'unknown content type']);
        }
        $locale = $input->locale ?? $this->locales->default();
        if (($errors = $this->locales->validate($locale)) !== []) {
            return Response::validation($errors);
        }
        $uuid = $this->entries->createEntry(
            (string) $type['uuid'],
            $locale,
            (int) $type['schema_version'],
            $this->actor($request),
        );
        return Response::created([
            'entry' => $this->entryPayload((array) $this->entries->findEntry($uuid)),
            'draft' => $this->entries->findDraft($uuid, $locale),
        ], 'Entry created.');
    }

    /**
     * Return the entry's identity/status record (NOT its field content — use getDraft() for
     * field values), or 404 if no entry has that UUID.
     *
     * @param string $uuid Entry UUID
     */
    #[ApiOperation(
        summary: 'Get an entry',
        description: 'Identity and status only, not field content — use the draft endpoint for values.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, schema: EntryResultData::class, description: 'The entry.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'No entry with that UUID.')]
    // 401/403/429/500 inferred from middleware + documentation.errors config.
    public function show(Request $request, string $uuid): Response
    {
        $entry = $this->entries->findEntry($uuid);
        return $entry === null
            ? Response::notFound('Entry not found.')
            : Response::success(['entry' => $this->entryPayload($entry)], 'Entry retrieved.');
    }

    /**
     * The EntryData wire shape: the raw row plus human context for
     * uuid-holding admin surfaces (homepage card, nav) — the type SLUG and
     * the list's derived display title.
     *
     * @param array<string,mixed> $entry
     * @return array<string,mixed>
     */
    private function entryPayload(array $entry): array
    {
        $type = $this->types->findByUuid((string) $entry['content_type_uuid']);
        $entry['content_type'] = $type !== null ? (string) $type['slug'] : null;
        $entry['display_title'] = $this->entries->displayTitle(
            (string) $entry['uuid'],
            $this->locales->default(),
        );
        return $entry;
    }

    /**
     * Return the current working draft for the entry+locale — field values plus the
     * `lock_version` the client must echo back on save — or 404 if no draft exists for that
     * entry/locale pair.
     *
     * @param string $uuid   Entry UUID
     * @param string $locale BCP-47 locale, e.g. "en"
     */
    #[ApiOperation(
        summary: 'Get an entry\'s draft for a locale',
        description: 'Returns field values plus the `lock_version` to echo back on save.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, schema: DraftResultData::class, description: 'The draft.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'No draft for that entry/locale.')]
    // 401/403/429/500 inferred from middleware + documentation.errors config.
    public function getDraft(Request $request, string $uuid, string $locale): Response
    {
        if (($errors = $this->locales->validate($locale)) !== []) {
            return Response::validation($errors);
        }
        $draft = $this->entries->findDraft($uuid, $locale);
        if ($draft !== null && $this->schemaProjector !== null) {
            $entry = $this->entries->findEntry($uuid);
            if ($entry !== null) {
                $draft['fields'] = $this->schemaProjector->project(
                    (string) $entry['content_type_uuid'],
                    (int) $draft['schema_version'],
                    (array) $draft['fields'],
                );
            }
        }
        return $draft === null
            ? Response::notFound('Draft not found.')
            : Response::success(['draft' => $draft], 'Draft retrieved.');
    }

    /**
     * Validate and persist the draft field values for the entry+locale under optimistic
     * concurrency. The flow is: resolve the entry (404 if gone) → validate the submitted
     * `fields` against the content type schema (a {@see ValidationException} → 422) → call
     * {@see EntryRepository::saveDraft()} passing the client's `lock_version`. A stale
     * lock_version trips the repository's {@see OptimisticLockException}, which is caught here
     * and returned as 409 carrying the current draft (code `STALE_DRAFT`) so the client can
     * rebase and retry. On success the reloaded, version-bumped draft is returned.
     *
     * @param string $uuid   Entry UUID
     * @param string $locale BCP-47 locale, e.g. "en"
     */
    #[ApiOperation(
        summary: 'Save an entry\'s draft (optimistic-locked)',
        description: 'Optimistic-locked: pass the `lock_version` from the last read; a stale value yields '
            . '409 carrying the current draft so the client can rebase.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, schema: DraftResultData::class, description: 'Draft saved.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'No entry with that UUID.')]
    #[ApiResponse(
        409,
        schema: ErrorResponse::class,
        envelope: false,
        description: 'Stale lock_version — the draft was modified by another writer.',
    )]
    #[ApiResponse(
        422,
        schema: ErrorResponse::class,
        envelope: false,
        description: 'Field validation failed against the content type schema.',
    )]
    // 401/403/429/500 inferred from middleware + documentation.errors config.
    public function saveDraft(SaveDraftData $input, Request $request, string $uuid, string $locale): Response
    {
        if (($errors = $this->locales->validate($locale)) !== []) {
            return Response::validation($errors);
        }
        $entry = $this->entries->findEntry($uuid);
        if ($entry === null) {
            return Response::notFound('Entry not found.');
        }
        $schema = $this->types->schemaFor((string) $entry['content_type_uuid']);
        // Block-migration write gate (spec §3): saving a payload containing a
        // migrating block type would strip its old keys against the flipped schema.
        try {
            $this->gate?->assertWritable($input->fields, $schema);
        } catch (BlockMigrationInProgressException $e) {
            return Response::error($e->getMessage(), Response::HTTP_CONFLICT, [
                'code' => 'BLOCK_MIGRATION_IN_PROGRESS',
                'block_type' => $e->slug,
            ]);
        }
        try {
            $clean = $this->validator->validate($schema, $input->fields);
        } catch (ValidationException $e) {
            return Response::validation($e->errors());
        }
        $type = $this->types->findByUuid((string) $entry['content_type_uuid']);
        try {
            $this->entries->saveDraft(
                $uuid,
                $locale,
                $clean,
                (int) $type['schema_version'],
                $input->lock_version ?? -1,
                $this->actor($request),
            );
        } catch (OptimisticLockException) {
            return Response::error('Draft was modified by another writer.', Response::HTTP_CONFLICT, [
                'code' => 'STALE_DRAFT',
                'current' => $this->entries->findDraft($uuid, $locale),
            ]);
        }
        // Clear-on-save (loop C spec §3): the DB draft now matches the working
        // tree — a stale stash must not shadow later preview refreshes.
        $this->workingCopies?->clear($uuid, $locale);
        return Response::success(['draft' => $this->entries->findDraft($uuid, $locale)], 'Draft saved.');
    }

    /**
     * Apply the CURRENT working fields as an ephemeral preview (loop C spec §2):
     * validate with the exact saveDraft guard set, stash the CLEANED fields in
     * cache keyed by {entry, locale}, persist nothing. /_preview/{token} then
     * overlays the stash over the DB draft until Save, the next Apply, or TTL.
     */
    #[ApiOperation(
        summary: 'Apply working fields as an ephemeral preview',
        description: 'Validates the submitted fields with the same guards as a draft save and stashes the '
            . 'cleaned result so the preview session renders unsaved work. Nothing is persisted; a successful '
            . 'draft save clears the stash.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, description: 'Working copy applied to the preview session.')]
    #[ApiResponse(403, schema: ErrorResponse::class, envelope: false, description: 'Invalid or re-pointed token.')]
    #[ApiResponse(
        409,
        schema: ErrorResponse::class,
        envelope: false,
        description: 'Version-pinned token (PREVIEW_VERSION_PINNED) or active block migration '
            . '(BLOCK_MIGRATION_IN_PROGRESS).',
    )]
    #[ApiResponse(410, schema: ErrorResponse::class, envelope: false, description: 'The preview token has expired.')]
    #[ApiResponse(422, schema: ErrorResponse::class, envelope: false, description: 'Field validation failed.')]
    // 401/403(permission)/429/500 inferred from middleware + documentation.errors config.
    public function applyPreview(ApplyPreviewData $input, Request $request, string $uuid, string $locale): Response
    {
        if ($this->workingCopies === null) {
            return Response::error('Preview apply is unavailable.', 503);
        }
        // 1. Verify the token — same fail-closed mapping as PreviewController::show().
        try {
            $token = PreviewToken::verify($input->token, $this->previewKey($this->context), time());
        } catch (PreviewTokenException $e) {
            return $e->isExpired()
                ? Response::error('Preview link expired', 410)
                : Response::forbidden('Invalid preview token');
        }
        // 2. Bind to the route: a token can never be pointed at another entry+locale.
        if ($token->entryUuid !== $uuid || $token->locale !== $locale) {
            return Response::forbidden('Invalid preview token');
        }
        // Version-pinned tokens conflict with immutable-version semantics (hard pin):
        // the token is VALID, the operation is not — 409, not 422. Never stash.
        if ($token->versionUuid !== null) {
            return Response::error(
                'A version-pinned preview cannot apply a working copy.',
                Response::HTTP_CONFLICT,
                ['code' => 'PREVIEW_VERSION_PINNED'],
            );
        }
        // 3. Locale + entry resolution (mirror saveDraft).
        if (($errors = $this->locales->validate($locale)) !== []) {
            return Response::validation($errors);
        }
        $entry = $this->entries->findEntry($uuid);
        if ($entry === null) {
            return Response::notFound('Entry not found.');
        }
        // 4. Payload cap: the stash is a cache row — never a cache-pressure primitive.
        $encoded = json_encode($input->fields);
        if ($encoded === false || strlen($encoded) > 1048576) {
            return Response::error('Preview payload too large.', 413);
        }
        $schema = $this->types->schemaFor((string) $entry['content_type_uuid']);
        // 5.–6. The exact saveDraft guard order (loop C spec §2, load-bearing):
        // migration gate FIRST, then the validator; identical response shapes.
        try {
            $this->gate?->assertWritable($input->fields, $schema);
        } catch (BlockMigrationInProgressException $e) {
            return Response::error($e->getMessage(), Response::HTTP_CONFLICT, [
                'code' => 'BLOCK_MIGRATION_IN_PROGRESS',
                'block_type' => $e->slug,
            ]);
        }
        try {
            $clean = $this->validator->validate($schema, $input->fields);
        } catch (ValidationException $e) {
            return Response::validation($e->errors());
        }
        // 7. Stash the CLEANED fields only, TTL capped to the token's remaining life.
        $ttl = min(max($token->expiresAt - time(), 1), 300);
        $this->workingCopies->put($uuid, $locale, $clean, $ttl);
        return Response::success(['applied_at' => date('c')], 'Preview applied.');
    }

    /**
     * Discard the mutable working draft for an entry+locale. Published content and version
     * history are untouched.
     *
     * @param string $uuid   Entry UUID
     * @param string $locale BCP-47 locale, e.g. "en"
     */
    #[ApiOperation(
        summary: 'Discard an entry draft',
        description: 'Drops the working draft only; published content is untouched.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, description: 'Draft discarded.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'No draft for that entry/locale.')]
    public function discardDraft(Request $request, string $uuid, string $locale): Response
    {
        if (($errors = $this->locales->validate($locale)) !== []) {
            return Response::validation($errors);
        }
        if ($this->entries->findDraft($uuid, $locale) === null) {
            return Response::notFound('Draft not found.');
        }
        $this->entries->discardDraft($uuid, $locale);
        return Response::success([], 'Draft discarded.');
    }

    /**
     * Soft-delete an entry after checking reverse references.
     *
     * @param string $uuid Entry UUID
     */
    #[ApiOperation(
        summary: 'Delete an entry',
        description: 'Soft-delete; refused (409) while published content still references the entry.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, description: 'Entry deleted.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'No entry with that UUID.')]
    #[ApiResponse(
        409,
        schema: ErrorResponse::class,
        envelope: false,
        description: 'Entry is referenced by published content.',
    )]
    public function destroy(Request $request, string $uuid): Response
    {
        if ($this->entries->findEntry($uuid) === null) {
            return Response::notFound('Entry not found.');
        }
        $sources = $this->references->referencesTo($uuid);
        if ($sources !== []) {
            return Response::error('Entry is referenced by other content.', Response::HTTP_CONFLICT, [
                'code' => 'ENTRY_REFERENCED',
                'references' => $sources,
            ]);
        }
        $this->entries->softDelete($uuid);
        return Response::success([], 'Entry deleted.');
    }

    /**
     * List delivery routes assigned to an entry.
     *
     * @param string $uuid Entry UUID
     */
    #[ApiOperation(
        summary: 'List entry routes',
        description: 'Route slugs assigned across all the entry\'s locales.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, description: 'Entry routes.')]
    public function routes(Request $request, string $uuid): Response
    {
        return Response::success(['routes' => $this->routes->forEntry($uuid)], 'Routes retrieved.');
    }

    /**
     * List the locales that currently have an editable draft, published pin, or route for
     * an entry. This is the backend contract the admin UI uses to show translation state.
     *
     * @param string $uuid Entry UUID
     */
    #[ApiOperation(
        summary: 'List entry locale variants',
        description: 'Per-locale draft, publication, and route state — the entry\'s translation status.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, schema: EntryLocalesResultData::class, description: 'Entry locale variants.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'No entry with that UUID.')]
    public function locales(Request $request, string $uuid): Response
    {
        if ($this->entries->findEntry($uuid) === null) {
            return Response::notFound('Entry not found.');
        }

        return Response::success(['locales' => $this->entries->localeSummary($uuid)], 'Locales retrieved.');
    }

    /**
     * Create a mutable draft for a target locale, optionally copying fields from an
     * existing source-locale draft. Published content is not changed until publish.
     *
     * @param string $uuid   Entry UUID
     * @param string $locale Target BCP-47 locale, e.g. "fr"
     */
    #[ApiOperation(
        summary: 'Create an entry locale draft',
        description: 'Optionally seeds the new draft by copying the current draft from `source_locale`.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(201, schema: DraftResultData::class, description: 'Locale draft created.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'No entry with that UUID.')]
    #[ApiResponse(409, schema: ErrorResponse::class, envelope: false, description: 'Draft already exists.')]
    #[ApiResponse(422, schema: ErrorResponse::class, envelope: false, description: 'Invalid locale or source locale.')]
    public function createLocaleDraft(
        CopyLocaleData $input,
        Request $request,
        string $uuid,
        string $locale,
    ): Response {
        if (($errors = $this->locales->validate($locale)) !== []) {
            return Response::validation($errors);
        }
        if ($input->source_locale !== null) {
            $sourceErrors = $this->locales->validate($input->source_locale, 'source_locale');
            if ($sourceErrors !== []) {
                return Response::validation($sourceErrors);
            }
        }

        $entry = $this->entries->findEntry($uuid);
        if ($entry === null) {
            return Response::notFound('Entry not found.');
        }
        $type = $this->types->findByUuid((string) $entry['content_type_uuid']);
        if ($type === null) {
            return Response::validation(['content_type' => 'unknown content type']);
        }
        $schema = $this->types->schemaFor((string) $entry['content_type_uuid']);

        try {
            $draft = $this->entries->createLocaleDraft(
                $uuid,
                $locale,
                (int) $type['schema_version'],
                $this->actor($request),
                $input->source_locale,
                $input->overwrite,
                $schema,
            );
        } catch (\InvalidArgumentException $e) {
            return Response::validation(['source_locale' => $e->getMessage()]);
        } catch (\RuntimeException $e) {
            return Response::error($e->getMessage(), Response::HTTP_CONFLICT, ['code' => 'DRAFT_EXISTS']);
        }

        return Response::created(['draft' => $draft], 'Locale draft created.');
    }

    /**
     * Assign or replace the route slug for an entry+locale.
     *
     * @param string $uuid   Entry UUID
     * @param string $locale BCP-47 locale, e.g. "en"
     */
    #[ApiOperation(
        summary: 'Assign an entry route',
        description: 'Replaces any existing route slug for the entry+locale.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, description: 'Route assigned.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'No entry with that UUID.')]
    #[ApiResponse(409, schema: ErrorResponse::class, envelope: false, description: 'Slug already in use.')]
    public function assignRoute(AssignRouteData $input, Request $request, string $uuid, string $locale): Response
    {
        if (($errors = $this->locales->validate($locale)) !== []) {
            return Response::validation($errors);
        }
        $entry = $this->entries->findEntry($uuid);
        if ($entry === null) {
            return Response::notFound('Entry not found.');
        }
        $typeUuid = (string) $entry['content_type_uuid'];
        $existing = $this->routes->findBySlug($typeUuid, $locale, $input->slug);
        if ($existing !== null && (string) $existing['entry_uuid'] !== $uuid) {
            return Response::error(
                'Route slug already in use.',
                Response::HTTP_CONFLICT,
                ['code' => 'ROUTE_TAKEN']
            );
        }
        // Root-mounted types claim the slug directly under / — the global root
        // namespace (types, reserved, locales, other root pages/redirects)
        // must stay collision-free (root-mounted-types spec §3).
        $typeRow = $this->types->findByUuid($typeUuid);
        if (($typeRow['mount_at_root'] ?? false) === true && $this->rootGuard !== null) {
            $conflicts = $this->rootGuard->conflictsForSlug($locale, $input->slug, $uuid);
            if ($conflicts !== []) {
                return Response::error(
                    'Root slug unavailable: ' . implode('; ', $conflicts),
                    Response::HTTP_CONFLICT,
                    ['code' => 'ROOT_SLUG_TAKEN', 'conflicts' => $conflicts]
                );
            }
        }
        $this->routes->assign($uuid, $typeUuid, $locale, $input->slug);
        return Response::success(['routes' => $this->routes->forEntry($uuid)], 'Route assigned.');
    }

    /**
     * Remove the route slug for an entry+locale.
     *
     * @param string $uuid   Entry UUID
     * @param string $locale BCP-47 locale, e.g. "en"
     */
    #[ApiOperation(
        summary: 'Remove an entry route',
        description: 'Idempotent — succeeds even when no route is assigned.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, description: 'Route removed.')]
    public function removeRoute(Request $request, string $uuid, string $locale): Response
    {
        if (($errors = $this->locales->validate($locale)) !== []) {
            return Response::validation($errors);
        }
        $this->routes->remove($uuid, $locale);
        return Response::success([], 'Route removed.');
    }

    /**
     * The authenticated principal's id for audit attribution (created_by / updated_by /
     * event actor), or null when the request carries no resolved {@see UserIdentity}.
     */
    private function actor(Request $request): ?string
    {
        return ActorHelper::uuidFromRequest($request);
    }
}

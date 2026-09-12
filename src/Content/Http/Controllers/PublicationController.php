<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\Controllers;

use Thallo\Core\Content\Blocks\Migration\BlockMigrationInProgressException;
use Thallo\Core\Content\Blocks\Migration\UnknownBlockTypeException;
use Thallo\Core\Content\Http\DTOs\RollbackData;
use Thallo\Core\Content\Http\DTOs\Responses\Publication\RollbackResultData;
use Thallo\Core\Content\Http\DTOs\Responses\Publication\VersionResultData;
use Thallo\Core\Content\Localization\ContentLocaleService;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Repositories\VersionRepository;
use Thallo\Core\Content\Schema\Migration\SchemaProjector;
use Thallo\Core\Content\Services\PublishService;
use Thallo\Core\Content\Validation\ValidationException;
use Thallo\Core\Http\DTOs\ErrorResponse;
use Thallo\Core\Support\ActorHelper;
use Glueful\Auth\UserIdentity;
use Glueful\Http\Response;
use Thallo\Contracts\Authoring\PublishBlocked;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The publication-lifecycle endpoints of the admin API — publish, unpublish, and roll back
 * an entry's locale. All three delegate the transactional work (snapshot/pin/validate) to
 * {@see PublishService}; this controller's only job is to translate request input and the
 * service's exceptions into the framework {@see Response} envelope.
 *
 * Error mapping: a {@see ValidationException} from the schema validator becomes a 422, while
 * the "no such entry/draft/version" {@see \RuntimeException}s the service raises are caught
 * and mapped to 404 (publish) or 422 (rollback) so they never reach the framework handler.
 * Every route is permission-gated (`content.publish`) by middleware, so authz failures
 * surface as 401/403 before these methods run.
 */
final class PublicationController
{
    public function __construct(
        private readonly PublishService $publisher,
        private readonly VersionRepository $versions,
        private readonly ContentLocaleService $locales,
        private readonly ?EntryRepository $entries = null,
        private readonly ?SchemaProjector $schemaProjector = null,
    ) {
    }

    /**
     * Publish the entry's current draft for the locale via {@see PublishService::publish()},
     * which validates the draft, snapshots it as an immutable version, and pins it.
     *
     * Both failure modes are handled here so nothing propagates: a schema-validation failure
     * becomes 422, and a missing entry/draft (raised as a {@see \RuntimeException}) becomes 404.
     *
     * @param string $uuid   Entry UUID
     * @param string $locale BCP-47 locale, e.g. "en"
     */
    #[ApiOperation(
        summary: 'Publish an entry\'s draft',
        description: 'Snapshots the current draft into an immutable version, pins it, and makes it visible '
            . 'to the delivery API.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, schema: VersionResultData::class, description: 'Entry published.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'No entry/draft to publish.')]
    #[ApiResponse(
        409,
        schema: ErrorResponse::class,
        envelope: false,
        description: 'A publish gate blocked the publish (e.g. review workflow: not approved).',
    )]
    #[ApiResponse(
        422,
        schema: ErrorResponse::class,
        envelope: false,
        description: 'Draft fields fail schema validation.',
    )]
    // 401/403/429/500 inferred from middleware + documentation.errors config.
    public function publish(Request $request, string $uuid, string $locale): Response
    {
        if (($errors = $this->locales->validate($locale)) !== []) {
            return Response::validation($errors);
        }
        try {
            $versionUuid = $this->publisher->publish($uuid, $locale, $this->actor($request));
        } catch (ValidationException $e) {
            return Response::validation($e->errors());
        } catch (PublishBlocked $e) {
            // MUST precede the RuntimeException catch (PublishBlocked extends it). Spec-pinned
            // shape: the message is the SPA-ready sentence; details.workflow_state drives the
            // editor badge without message parsing.
            return Response::error($e->reason, 409, ['workflow_state' => $e->state]);
        } catch (BlockMigrationInProgressException $e) {
            // Also precedes RuntimeException (same inheritance). Block-migrations
            // spec §3: the stored draft contains a migrating block type.
            return Response::error($e->getMessage(), 409, [
                'code' => 'BLOCK_MIGRATION_IN_PROGRESS',
                'block_type' => $e->slug,
            ]);
        } catch (\RuntimeException $e) {
            return Response::notFound($e->getMessage());
        }
        return Response::success(['version_uuid' => $versionUuid], 'Entry published.');
    }

    /**
     * Remove the publication pin for the entry+locale so the delivery API stops serving it;
     * the underlying versions are retained. Idempotent and unconditional — if there is no
     * pin to remove the service simply does nothing, so this always returns 200.
     *
     * @param string $uuid   Entry UUID
     * @param string $locale BCP-47 locale, e.g. "en"
     */
    #[ApiOperation(
        summary: 'Unpublish an entry',
        description: 'Removes the publication pin (versions are retained); idempotent — succeeds even when '
            . 'nothing is published.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, description: 'Entry unpublished.')]
    // 401/403/429/500 inferred from middleware + documentation.errors config.
    public function unpublish(Request $request, string $uuid, string $locale): Response
    {
        if (($errors = $this->locales->validate($locale)) !== []) {
            return Response::validation($errors);
        }
        $this->publisher->unpublish($uuid, $locale);
        return Response::success([], 'Entry unpublished.');
    }

    /**
     * Re-pin a previously published version as the current published version for the
     * entry+locale (a re-publish of an existing snapshot, not a new version). The target
     * `version_uuid` is read from the JSON body; a missing/blank value short-circuits to 422
     * before touching the service, and a version that does not belong to this entry+locale
     * (the service raises a {@see \RuntimeException}) is caught and also mapped to 422.
     *
     * @param string $uuid   Entry UUID
     * @param string $locale BCP-47 locale, e.g. "en"
     */
    #[ApiOperation(
        summary: 'Roll back to a previous version',
        description: 'Re-pins an existing `version_uuid` as the published version; no new version is '
            . 'created.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(
        200,
        schema: RollbackResultData::class,
        description: 'Rolled back — names the version ACTUALLY pinned (a newly materialized '
            . 'one when block migrations re-projected the fields).',
    )]
    #[ApiResponse(
        422,
        schema: ErrorResponse::class,
        envelope: false,
        description: 'Missing or invalid version_uuid (or it does not belong to this entry+locale).',
    )]
    // 401/403/429/500 inferred from middleware + documentation.errors config.
    public function rollback(RollbackData $input, Request $request, string $uuid, string $locale): Response
    {
        if (($errors = $this->locales->validate($locale)) !== []) {
            return Response::validation($errors);
        }
        // Structural validation (version_uuid present + non-blank) is done by the hydrated DTO.
        // Whether the version belongs to this entry+locale is a domain rule and stays here.
        try {
            $pinned = $this->publisher->rollback($uuid, $locale, $input->version_uuid, $this->actor($request));
        } catch (UnknownBlockTypeException $e) {
            // Precedes the generic catch to document the contract (block-migrations
            // spec §5): the version references a hard-deleted block type — blocked
            // BEFORE any write, the message names the type.
            return Response::validation(['version_uuid' => $e->getMessage()]);
        } catch (\RuntimeException $e) {
            return Response::validation(['version_uuid' => $e->getMessage()]);
        }
        // The ACTUAL pinned version: rollback MATERIALIZES a new projected version
        // when block migrations changed the fields — echoing the input would lie.
        return Response::success($pinned, 'Rolled back to version.');
    }

    /**
     * List every immutable published version for an entry+locale, newest first.
     *
     * @param string $uuid   Entry UUID
     * @param string $locale BCP-47 locale, e.g. "en"
     */
    #[ApiOperation(
        summary: 'List entry versions',
        description: 'Immutable published versions, newest first.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, description: 'Entry versions.')]
    public function versions(Request $request, string $uuid, string $locale): Response
    {
        if (($errors = $this->locales->validate($locale)) !== []) {
            return Response::validation($errors);
        }
        $versions = $this->versions->versionsFor($uuid, $locale);
        if ($this->entries !== null && $this->schemaProjector !== null) {
            $entry = $this->entries->findEntry($uuid);
            if ($entry !== null) {
                foreach ($versions as $i => $version) {
                    $fields = is_string($version['fields'] ?? null)
                        ? (json_decode((string) $version['fields'], true) ?? [])
                        : (array) ($version['fields'] ?? []);
                    $versions[$i]['fields'] = $this->schemaProjector->project(
                        (string) $entry['content_type_uuid'],
                        (int) ($version['schema_version'] ?? 0),
                        $fields,
                    );
                }
            }
        }

        return Response::success(['versions' => $versions], 'Versions retrieved.');
    }

    /**
     * The authenticated principal's id for audit attribution, or null when the request
     * carries no resolved {@see UserIdentity} (e.g. system/internal callers).
     */
    private function actor(Request $request): ?string
    {
        return ActorHelper::uuidFromRequest($request);
    }
}

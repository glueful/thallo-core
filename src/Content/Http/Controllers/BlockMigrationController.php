<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\Controllers;

use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Blocks\Migration\BlockMigrationRepository;
use Thallo\Core\Content\Blocks\Migration\BlockMigrationService;
use Thallo\Core\Content\Http\DTOs\MigrationData;
use Thallo\Core\Content\Schema\SchemaParseException;
use Thallo\Core\Content\Services\ActiveMigrationException;
use Thallo\Core\Http\DTOs\ErrorResponse;
use Thallo\Core\Support\ActorHelper;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Block-type schema migrations (block-migrations spec §2) — MigrationController's
 * shape over the block registry. While a migration is active (running OR failed),
 * saves/publishes of entries containing the type 409 via the write gate, and a
 * second declaration 409s here.
 */
final class BlockMigrationController
{
    public function __construct(
        private readonly BlockTypeRepository $blockTypes,
        private readonly BlockMigrationService $service,
        private readonly BlockMigrationRepository $migrations,
    ) {
    }

    #[ApiOperation(
        summary: 'Start a block-type schema migration',
        description: 'Runs asynchronously. `ops` is a list of `{op:"rename",from,to}` / '
            . '`{op:"delete",name}`; one active migration per block type (409 — failed '
            . 'migrations stay active until re-driven to completion).',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(201, description: 'Migration started; poll the returned migration row for progress.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'No block type with that slug.')]
    #[ApiResponse(409, schema: ErrorResponse::class, envelope: false, description: 'A migration is already active.')]
    #[ApiResponse(422, schema: ErrorResponse::class, envelope: false, description: 'Invalid migration operations.')]
    public function store(MigrationData $input, Request $request, string $slug): Response
    {
        $type = $this->blockTypes->findBySlug($slug);
        if ($type === null) {
            return Response::notFound('Block type not found.');
        }

        try {
            $uuid = $this->service->migrate((string) $type['uuid'], $input->ops, $this->actor($request));
        } catch (ActiveMigrationException $e) {
            return Response::error($e->getMessage(), Response::HTTP_CONFLICT);
        } catch (SchemaParseException $e) {
            return Response::validation(['ops' => $e->getMessage()]);
        }

        return Response::created(['migration' => $this->migrations->find($uuid)], 'Migration started.');
    }

    #[ApiOperation(summary: 'List schema migrations for a block type', tags: ['Thallo Admin'])]
    #[ApiResponse(200, description: 'Schema migrations for the block type.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'No block type with that slug.')]
    public function index(Request $request, string $slug): Response
    {
        $type = $this->blockTypes->findBySlug($slug);
        if ($type === null) {
            return Response::notFound('Block type not found.');
        }

        return Response::success(
            ['migrations' => $this->migrations->forType((string) $type['uuid'])],
            'Migrations retrieved.'
        );
    }

    #[ApiOperation(summary: 'Get one block-type schema migration', tags: ['Thallo Admin'])]
    #[ApiResponse(200, description: 'The migration row with progress counters and failure report.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'No such migration.')]
    public function show(Request $request, string $slug, string $migrationUuid): Response
    {
        $type = $this->blockTypes->findBySlug($slug);
        if ($type === null) {
            return Response::notFound('Block type not found.');
        }

        $row = $this->migrations->find($migrationUuid);
        if ($row === null || (string) $row['block_type_uuid'] !== (string) $type['uuid']) {
            return Response::notFound('Migration not found.');
        }

        return Response::success(['migration' => $row], 'Migration retrieved.');
    }

    private function actor(Request $request): ?string
    {
        return ActorHelper::uuidFromRequest($request);
    }
}

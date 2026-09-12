<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\Controllers;

use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Blocks\BlockUsageScanner;
use Thallo\Core\Content\Blocks\Migration\BlockMigrationRepository;
use Thallo\Core\Content\Http\DTOs\BlockTypeData;
use Thallo\Core\Content\Http\DTOs\FieldDefinitionData;
use Thallo\Core\Content\Http\DTOs\Responses\BlockTypes\BlockTypeListData;
use Thallo\Core\Content\Http\DTOs\Responses\BlockTypes\BlockTypeResultData;
use Thallo\Core\Content\Http\DTOs\UpdateBlockTypeData;
use Thallo\Core\Content\Schema\SchemaParseException;
use Thallo\Core\Content\Starter\Kinds\BlockTypeKind;
use Thallo\Core\Http\DTOs\ErrorResponse;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The global block-type registry API (block-builder spec §1–§2). Slugs are immutable
 * (blocks/{slug}.twig contract); removal is deactivation only — inactive types
 * disappear from the add-picker while existing content keeps validating and rendering.
 * Read routes require `content.view`; mutating routes require `content.manage`
 * (enforced by route middleware).
 */
final class BlockTypeController
{
    public function __construct(
        private readonly BlockTypeRepository $blockTypes,
        private readonly BlockUsageScanner $usageScanner,
        private readonly BlockMigrationRepository $blockMigrations,
        private readonly BlockTypeKind $starters,
    ) {
    }

    #[ApiOperation(
        summary: 'List block types',
        description: 'A pack\'s block types (Commerce, Accounts) are listed only while its capability '
            . 'is on; their rows are kept, not deleted, while it is off.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, schema: BlockTypeListData::class, description: 'All block types, active first.')]
    public function index(Request $request): Response
    {
        $hidden = $this->starters->hiddenSlugs();
        $listed = $hidden === []
            ? $this->blockTypes->all()
            : array_values(array_filter(
                $this->blockTypes->all(),
                static fn (array $row): bool => !in_array((string) $row['slug'], $hidden, true),
            ));

        return Response::success(['block_types' => $listed], 'Block types retrieved.');
    }

    #[ApiOperation(
        summary: 'Create a block type',
        description: '`slug` is a unique lowercase identifier and IMMUTABLE after creation — it is the '
            . 'blocks/{slug}.twig template contract.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(201, schema: BlockTypeResultData::class, description: 'Block type created.')]
    #[ApiResponse(
        422,
        schema: ErrorResponse::class,
        envelope: false,
        description: 'Duplicate slug or invalid block schema (no nested blocks/localized/filterable fields).',
    )]
    public function store(BlockTypeData $input, Request $request): Response
    {
        if ($this->blockTypes->findBySlug($input->slug) !== null) {
            return Response::validation(['slug' => "block type '{$input->slug}' already exists"]);
        }
        try {
            $uuid = $this->blockTypes->create([
                'slug' => $input->slug,
                'label' => trim($input->label),
                'icon' => $input->icon,
                'category' => $input->category,
                'description' => $input->description,
                'schema' => array_map(
                    static fn (FieldDefinitionData $f): array => $f->toArray(),
                    $input->schema,
                ),
            ]);
        } catch (SchemaParseException $e) {
            return Response::validation(['schema' => $e->getMessage()]);
        }
        return Response::created(
            ['block_type' => $this->blockTypes->findByUuid($uuid)],
            'Block type created.',
        );
    }

    #[ApiOperation(summary: 'One block type', tags: ['Thallo Admin'])]
    #[ApiResponse(200, schema: BlockTypeResultData::class, description: 'The block type with its schema.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'Unknown slug.')]
    public function show(Request $request, string $slug): Response
    {
        $row = $this->blockTypes->findBySlug($slug);
        return $row === null
            ? Response::error('Unknown block type.', 404)
            : Response::success(['block_type' => $row]);
    }

    #[ApiOperation(summary: 'Update a block type (slug is immutable)', tags: ['Thallo Admin'])]
    #[ApiResponse(200, schema: BlockTypeResultData::class, description: 'Updated.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'Unknown slug.')]
    #[ApiResponse(
        422,
        schema: ErrorResponse::class,
        envelope: false,
        description: 'Invalid block schema (no nested blocks/localized/filterable fields).',
    )]
    public function update(UpdateBlockTypeData $input, Request $request, string $slug): Response
    {
        $row = $this->blockTypes->findBySlug($slug);
        if ($row === null) {
            return Response::error('Unknown block type.', 404);
        }
        try {
            $this->blockTypes->updateSchema(
                (string) $row['uuid'],
                array_map(static fn (FieldDefinitionData $f): array => $f->toArray(), $input->schema),
                trim($input->label),
                $input->icon,
                $input->description,
                $input->category,
            );
        } catch (SchemaParseException $e) {
            return Response::validation(['schema' => $e->getMessage()]);
        }
        return Response::success(
            ['block_type' => $this->blockTypes->findBySlug($slug)],
            'Block type updated.',
        );
    }

    #[ApiOperation(summary: 'Reactivate a block type', tags: ['Thallo Admin'])]
    #[ApiResponse(200, schema: BlockTypeResultData::class, description: 'Active — back in the block picker.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'Unknown slug.')]
    public function activate(string $slug): Response
    {
        return $this->setActive($slug, true);
    }

    #[ApiOperation(
        summary: 'Deactivate a block type (existing content keeps rendering/editing)',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, schema: BlockTypeResultData::class, description: 'Inactive — hidden from the picker.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'Unknown slug.')]
    public function deactivate(string $slug): Response
    {
        return $this->setActive($slug, false);
    }

    private function setActive(string $slug, bool $active): Response
    {
        $row = $this->blockTypes->findBySlug($slug);
        if ($row === null) {
            return Response::error('Unknown block type.', 404);
        }
        $this->blockTypes->setActive((string) $row['uuid'], $active);
        return Response::success(
            ['block_type' => $this->blockTypes->findBySlug($slug)],
            $active ? 'Block type activated.' : 'Block type deactivated.',
        );
    }

    #[ApiOperation(
        summary: 'Block type usage across current content',
        description: 'On-demand scan of current drafts + pinned publications (non-deleted entries, '
            . 'archived included, nested blocks counted). Historical versions never count. '
            . 'Content-type blockTypes picker allowlists are reported but never gate deletion.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, description: 'Usage counts per content type, samples, and allowlist appearances.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'Unknown slug.')]
    public function usage(Request $request, string $slug): Response
    {
        if ($this->blockTypes->findBySlug($slug) === null) {
            return Response::notFound('Block type not found.');
        }
        return Response::success($this->usageScanner->usage($slug), 'Block type usage.');
    }

    #[ApiOperation(
        summary: 'Hard-delete an UNUSED block type',
        description: 'Destructive cleanup for unused/mistaken types only (block-migrations spec §6): '
            . 'refuses while any current draft/publication uses the type (the usage scan re-runs '
            . 'server-side) or while a migration is active. No force flag — deactivate is the '
            . 'editorial path.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, description: 'Block type deleted.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'Unknown slug.')]
    #[ApiResponse(
        409,
        schema: ErrorResponse::class,
        envelope: false,
        description: 'In use by current content, or a migration is active.',
    )]
    public function destroy(Request $request, string $slug): Response
    {
        $row = $this->blockTypes->findBySlug($slug);
        if ($row === null) {
            return Response::notFound('Block type not found.');
        }
        if ($this->blockMigrations->activeForType((string) $row['uuid']) !== null) {
            return Response::error(
                'A migration is active for this block type.',
                Response::HTTP_CONFLICT,
                ['code' => 'BLOCK_MIGRATION_IN_PROGRESS'],
            );
        }
        $usage = $this->usageScanner->usage($slug); // server-side re-scan (spec §6)
        if ($usage['total'] > 0) {
            return Response::error(
                'Block type is in use by current content.',
                Response::HTTP_CONFLICT,
                ['code' => 'BLOCK_TYPE_IN_USE', 'usage' => $usage],
            );
        }
        $this->blockTypes->deleteBySlug($slug);
        return Response::success(['slug' => $slug], 'Block type deleted.');
    }
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\Controllers;

use Thallo\Core\Content\Palette\Normalized;
use Thallo\Core\Content\Palette\PaletteSnapshot;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Style\StyleCapabilities;
use Thallo\Contracts\Style\StyleClassProvider;
use Thallo\Core\Content\Http\DTOs\Responses\StyleClasses\StyleClassListData;
use Thallo\Core\Content\Http\DTOs\Responses\StyleClasses\StyleClassResultData;
use Thallo\Core\Content\Http\DTOs\Responses\StyleClasses\StyleClassUsageData;
use Thallo\Core\Content\Http\DTOs\Responses\StyleClasses\StyleClassJobData;
use Thallo\Core\Content\Http\DTOs\StyleClassData;
use Thallo\Core\Content\Http\DTOs\StyleClassJobRequestData;
use Thallo\Core\Content\Http\DTOs\UpdateStyleClassData;
use Thallo\Core\Content\Layouts\RequiredBlockClassGuard;
use Thallo\Core\Content\Style\Classes\StyleClassLocked;
use Thallo\Core\Content\Style\Classes\StyleClassNameTaken;
use Thallo\Core\Content\Style\Classes\StyleClassNotFound;
use Thallo\Core\Content\Style\Classes\StyleClassRepository;
use Thallo\Core\Content\Style\Classes\StyleClassJobRepository;
use Thallo\Core\Content\Style\Classes\StyleClassJobService;
use Thallo\Core\Content\Style\Classes\StyleClassUsage;
use Thallo\Core\Content\Style\Classes\StyleClassVersionConflict;
use Thallo\Core\Content\Style\SettingsValidator;
use Thallo\Core\Http\DTOs\ErrorResponse;

/**
 * Style classes (visual builder spec §4.1, §4.3, §4.5): site-owned, theme-independent records
 * a block composes through `settings.classes`. Read routes require `content.view`; mutating
 * routes require `styles.manage` (route middleware). The list is one snapshot and names its
 * generation; a write names the version it loaded and conflicts when the row moved on; delete
 * archives, so old revisions still restore.
 */
final class StyleClassController
{
    use \Thallo\Core\Content\Palette\CarriesPaletteFields;

    public function __construct(
        private readonly StyleClassRepository $classes,
        private readonly StyleClassProvider $provider,
        private readonly StyleClassUsage $usage,
        private readonly SettingsValidator $settings = new SettingsValidator(),
        private readonly ?StyleClassJobService $jobService = null,
        private readonly ?StyleClassJobRepository $jobs = null,
        /** Refuses an edit that would hide a layout's required block (type layouts plan C1). */
        private readonly ?RequiredBlockClassGuard $layouts = null,
        /** The palette fence (custom palette spec §4.3); null = unfenced. */
        private readonly ?\Thallo\Core\Content\Palette\PaletteFence $fence = null,
        private readonly ?\Thallo\Core\Content\Palette\PaletteNormalizer $normalizer = null,
        /** The palette fields its responses carry (custom palette plan Task 12). */
        private readonly ?\Thallo\Core\Content\Palette\PaletteResponseFields $paletteFields = null,
    ) {
    }

    /**
     * A class style through the palette fence (custom palette spec §4.3, §4.5): a running replacement's
     * source mapped, a fresh reference to a cleared colour refused; the class as stored is the basis.
     *
     * @template T
     * @param array<string,mixed> $style
     * @param callable(array<string,mixed>): T $write
     * @return T
     */
    private function fencedStyle(array $style, ?string $id, callable $write): mixed
    {
        if ($this->fence === null || $this->normalizer === null) {
            return $write($style);
        }
        $kind = \Thallo\Core\Content\Palette\ColorTokenWalker::KIND_CLASS;
        return $this->fence->write(
            function (PaletteSnapshot $s) use ($style, $id, $kind): Normalized {
                $stored = $id === null ? [] : (array) ($this->classes->find($id)['style'] ?? []);
                $basis = $this->normalizer?->basisOf($kind, null, ['style' => $stored]) ?? [];
                return $this->normalizer?->normalize($kind, ['style' => $style], $s, $basis)
                    ?? new Normalized(['style' => $style], false, false);
            },
            fn (array $doc) => $write((array) ($doc['style'] ?? [])),
        );
    }

    #[ApiOperation(
        summary: 'List style classes',
        description: 'Every class of the site, archived included, from one snapshot; `generation` names it.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, schema: StyleClassListData::class, description: 'The site\'s style classes.')]
    public function index(Request $request): Response
    {
        $this->provider->refresh();
        $snapshot = $this->provider->snapshot();
        // The classes read consistently with the palette generation (custom palette plan Task 12).
        [$classes, $palette] = $this->paletteLoad(fn (): array => $this->classes->all());
        return Response::success($palette + [
            'generation' => $snapshot->generation,
            'style_classes' => $classes,
        ], 'Style classes retrieved.');
    }

    #[ApiOperation(summary: 'Create a style class', tags: ['Thallo Admin'])]
    #[ApiResponse(201, schema: StyleClassResultData::class, description: 'Style class created.')]
    #[ApiResponse(422, schema: ErrorResponse::class, envelope: false, description: 'Name taken or invalid style.')]
    public function store(StyleClassData $input, Request $request): Response
    {
        [$style, $errors] = $this->style($input->style);
        if ($errors !== []) {
            return Response::validation($errors);
        }
        try {
            [$class, $palette] = $this->paletteSave(fn (): array => $this->fencedStyle(
                $style,
                null,
                fn (array $style): array => $this->classes->create([
                    'name' => $input->name,
                    'description' => $input->description,
                    'style' => $style,
                ]),
            ), $input->palette_through);
        } catch (\Thallo\Core\Content\Palette\PaletteRefusal $e) {
            return \Thallo\Core\Content\Palette\PaletteRefusalResponse::from($e);
        } catch (StyleClassNameTaken $e) {
            return Response::validation(['name' => $e->getMessage()]);
        } catch (\InvalidArgumentException $e) {
            return Response::validation(['name' => $e->getMessage()]);
        }
        return Response::created(['style_class' => $class] + $palette, 'Style class created.');
    }

    #[ApiOperation(summary: 'One style class', tags: ['Thallo Admin'])]
    #[ApiResponse(200, schema: StyleClassResultData::class, description: 'The style class.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'Unknown id.')]
    public function show(Request $request, string $id): Response
    {
        [$class, $palette] = $this->paletteLoad(fn (): ?array => $this->classes->find($id));
        if ($class === null) {
            return Response::notFound('Style class not found.');
        }
        return Response::success(['style_class' => $class] + $palette, 'Style class retrieved.');
    }

    #[ApiOperation(
        summary: 'Update a style class',
        description: '`version` is the version the client loaded; a stale one is 409 '
            . '`STYLE_CLASS_VERSION_CONFLICT` carrying `current_version`. Only the keys present change. '
            . 'Saving changes published pages immediately. A style that would hide a layout\'s required '
            . 'block — the product page\'s Product buy box — through a block holding it is refused (422, '
            . '`style.visibility`).',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, schema: StyleClassResultData::class, description: 'Style class updated.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'Unknown id.')]
    #[ApiResponse(409, schema: ErrorResponse::class, envelope: false, description: 'Stale version or locked.')]
    #[ApiResponse(422, schema: ErrorResponse::class, envelope: false, description: 'Name taken or invalid style.')]
    public function update(UpdateStyleClassData $input, Request $request, string $id): Response
    {
        $changes = [];
        if ($input->name !== null) {
            $changes['name'] = $input->name;
        }
        if ($input->description !== null) {
            $changes['description'] = $input->description;
        }
        if ($input->style !== null) {
            [$style, $errors] = $this->style($input->style);
            if ($errors !== []) {
                return Response::validation($errors);
            }
            $refusal = $this->layouts?->refusal($id, $style);
            if ($refusal !== null) {
                return Response::validation(['style.visibility' => $refusal]);
            }
            $changes['style'] = $style;
        }
        try {
            [$class, $palette] = $this->paletteSave(fn (): array => isset($changes['style'])
                ? $this->fencedStyle(
                    $changes['style'],
                    $id,
                    fn (array $style): array => $this->classes
                        ->update($id, $input->version, ['style' => $style] + $changes),
                )
                : $this->classes->update($id, $input->version, $changes), $input->palette_through);
        } catch (\Thallo\Core\Content\Palette\PaletteRefusal $e) {
            return \Thallo\Core\Content\Palette\PaletteRefusalResponse::from($e);
        } catch (StyleClassNotFound) {
            return Response::notFound('Style class not found.');
        } catch (StyleClassVersionConflict $e) {
            return Response::error('The style class was saved by someone else first.', Response::HTTP_CONFLICT, [
                'code' => 'STYLE_CLASS_VERSION_CONFLICT',
                'current_version' => $e->currentVersion,
            ]);
        } catch (StyleClassLocked $e) {
            return self::locked($e);
        } catch (StyleClassNameTaken | \InvalidArgumentException $e) {
            return Response::validation(['name' => $e->getMessage()]);
        }
        return Response::success(['style_class' => $class] + $palette, 'Style class updated.');
    }

    #[ApiOperation(
        summary: 'Archive a style class',
        description: 'Deletion archives the definition so old revisions still restore (spec §4.5). '
            . 'With `?unreferenced=1` a class nothing references is deleted outright — the editor uses it '
            . 'for a lift that could not preserve appearance; a referenced class answers 409 '
            . '`STYLE_CLASS_REFERENCED`.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, schema: StyleClassResultData::class, description: 'Style class archived or deleted.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'Unknown id.')]
    #[ApiResponse(409, schema: ErrorResponse::class, envelope: false, description: 'Locked or referenced.')]
    public function destroy(Request $request, string $id): Response
    {
        if ($request->query->getBoolean('unreferenced')) {
            $class = $this->classes->find($id);
            if ($class === null) {
                return Response::notFound('Style class not found.');
            }
            $references = $this->usage->of($id, $class['style'])['references'];
            if ($references > 0) {
                return Response::error('The style class is referenced; archive it instead.', Response::HTTP_CONFLICT, [
                    'code' => 'STYLE_CLASS_REFERENCED',
                    'references' => $references,
                ]);
            }
            try {
                $this->classes->deleteUnreferenced($id);
            } catch (StyleClassNotFound) {
                return Response::notFound('Style class not found.');
            }
            return Response::success(['style_class' => $class + ['deleted' => true]], 'Style class deleted.');
        }
        try {
            $class = $this->classes->archive($id);
        } catch (StyleClassNotFound) {
            return Response::notFound('Style class not found.');
        } catch (StyleClassLocked $e) {
            return self::locked($e);
        }
        return Response::success(['style_class' => $class], 'Style class archived.');
    }

    #[ApiOperation(
        summary: 'Where a style class is used',
        description: 'One reference per occurrence in one stored document across drafts, published '
            . 'entries, retained versions and regions (the published revision counted once); per '
            . 'property, active where the block has the capability and dormant elsewhere.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, schema: StyleClassUsageData::class, description: 'Usage counts.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'Unknown id.')]
    public function usage(Request $request, string $id): Response
    {
        $class = $this->classes->find($id);
        if ($class === null) {
            return Response::notFound('Style class not found.');
        }
        return Response::success(['usage' => $this->usage->of($id, $class['style'])], 'Usage retrieved.');
    }

    #[ApiOperation(
        summary: 'Queue a detach-everywhere or remove-everywhere job',
        description: 'Locks the class until the job completes (spec §4.5): no edit and no new reference '
            . 'meanwhile. `detach` writes what the class contributed into every block and removes the '
            . 'reference; `remove` removes the reference only and changes how pages look.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(202, schema: StyleClassJobData::class, description: 'Job queued.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'Unknown id.')]
    #[ApiResponse(409, schema: ErrorResponse::class, envelope: false, description: 'A job is already active.')]
    public function queueJob(StyleClassJobRequestData $input, Request $request, string $id): Response
    {
        if ($this->jobService === null || $this->jobs === null) {
            return Response::error('Style class jobs are not available.', Response::HTTP_SERVICE_UNAVAILABLE);
        }
        try {
            $jobId = $this->jobService->queue($id, $input->kind);
        } catch (StyleClassNotFound) {
            return Response::notFound('Style class not found.');
        } catch (StyleClassLocked $e) {
            return self::locked($e);
        }
        $job = $this->jobs->find($jobId);
        return Response::success(['job' => $job], 'Style class job queued.')->setStatusCode(Response::HTTP_ACCEPTED);
    }

    #[ApiOperation(summary: 'One style class job', tags: ['Thallo Admin'])]
    #[ApiResponse(200, schema: StyleClassJobData::class, description: 'The job with its progress.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'Unknown job.')]
    public function showJob(Request $request, string $id, string $job): Response
    {
        $row = $this->jobs?->find($job);
        if ($row === null || $row['class_id'] !== $id) {
            return Response::notFound('Style class job not found.');
        }
        return Response::success(['job' => $row], 'Style class job retrieved.');
    }

    /**
     * A class's style is validated against every property: it declares no capabilities (§4.1).
     *
     * @param array<string,mixed> $style
     * @return array{0: array<string,mixed>, 1: array<string,string>}
     */
    private function style(array $style): array
    {
        [$clean, $errors] = $this->settings->validate(['style' => $style], StyleCapabilities::all());
        $out = [];
        foreach ($errors as $path => $message) {
            $out[preg_replace('/^settings\./', '', $path) ?? $path] = $message;
        }
        return [$clean['style'] ?? [], $out];
    }

    private static function locked(StyleClassLocked $e): Response
    {
        return Response::error('A job holds this style class until it completes.', Response::HTTP_CONFLICT, [
            'code' => 'STYLE_CLASS_LOCKED',
            'job' => $e->job,
        ]);
    }
}

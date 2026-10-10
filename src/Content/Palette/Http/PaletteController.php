<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Palette\Http;

use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Support\ActorHelper;
use Thallo\Contracts\Style\PaletteProvider;
use Thallo\Core\Content\Palette\BrandColorInUse;
use Thallo\Core\Content\Palette\BrandColorUsage;
use Thallo\Core\Content\Palette\PaletteConflict;
use Thallo\Core\Content\Palette\PaletteHistoryExpired;
use Thallo\Core\Content\Palette\PaletteJob;
use Thallo\Core\Content\Palette\PaletteJobRepository;
use Thallo\Core\Content\Palette\PaletteMutations;
use Thallo\Core\Content\Palette\PaletteReplaceService;
use Thallo\Core\Content\Palette\PaletteReplacements;
use Thallo\Core\Settings\GeneralSettings;
use Thallo\Core\Settings\PaletteSettings;
use Thallo\Render\Theme\EffectivePalette;
use Thallo\Render\Theme\ThemeColors;
use Thallo\Render\Theme\ThemeDesign;

/**
 * The palette's admin endpoints (custom palette spec §4, §5): where a brand colour is used, clearing
 * and replacing it, and the contrast preview. Usage and every change need content.manage.
 */
final class PaletteController
{
    public function __construct(
        private readonly BrandColorUsage $usage,
        private readonly PaletteReplacements $replacements,
        private readonly ?PaletteMutations $mutations = null,
        private readonly ?GeneralSettings $settings = null,
        private readonly ?PaletteProvider $palette = null,
        /** The style schema's palette block, which a Clear answers with; null without the render pack. */
        private readonly ?\Thallo\Render\Http\Controllers\StyleSchemaController $schema = null,
        private readonly ?PaletteReplaceService $replace = null,
        private readonly ?PaletteJobRepository $jobs = null,
    ) {
    }

    /** GET /v1/admin/appearance/palette/brand/{id}/usage */
    #[ApiOperation(
        summary: 'Where a brand colour is used',
        description: 'Blocking documents (drafts, current publications, regions, layouts, saved sections, style '
            . 'classes) and historical versions naming the slot or its text colour. Requires `content.manage`.',
        tags: ['Thallo Settings'],
    )]
    #[ApiResponse(200, description: 'The usage.')]
    #[ApiResponse(404, description: 'No such slot.')]
    public function usage(int $id): Response
    {
        return Response::success(['usage' => $this->usage->of($id)]);
    }

    /** GET /v1/admin/appearance/palette/replacements?after=&through= */
    #[ApiOperation(
        summary: 'Completed brand colour replacements over a generation range',
        description: 'Every completed replacement record with `after < completed_generation <= through`, in '
            . 'completion order — the range an editor is missing. 410 `PALETTE_HISTORY_EXPIRED` when the range '
            . 'reaches below pruned history. Any style editor may read it: `content.edit`, `content.manage`, '
            . '`templates.manage` or `styles.manage`.',
        tags: ['Thallo Settings'],
    )]
    #[ApiResponse(200, description: 'The batch.')]
    #[ApiResponse(410, description: 'The range reaches below pruned history; reload the editor.')]
    public function replacements(Request $request): Response
    {
        $after = filter_var($request->query->get('after'), FILTER_VALIDATE_INT);
        $through = filter_var($request->query->get('through'), FILTER_VALIDATE_INT);
        if ($after === false || $through === false || $after < 0 || $through < $after) {
            return Response::error('The range is invalid.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        try {
            return Response::success(['replacements' => $this->replacements->batch($after, $through)]);
        } catch (PaletteHistoryExpired) {
            return Response::error(
                'The palette history for this range has been pruned. Reload to keep editing.',
                410,
                ['code' => 'PALETTE_HISTORY_EXPIRED'],
            );
        }
    }

    /** DELETE /v1/admin/appearance/palette/brand/{id} */
    #[ApiOperation(
        summary: 'Clear a brand colour',
        description: 'Clears the slot when nothing blocking names it (drafts, current publications, regions, '
            . 'layouts, saved sections, style classes); historical versions never block. 409 with `usage` '
            . 'when something does, 409 with `conflict` while a replacement replaces or writes to the slot. '
            . 'Requires `content.manage`.',
        tags: ['Thallo Settings'],
    )]
    #[ApiResponse(
        200,
        description: 'Cleared; the style schema\'s palette block and the stored brand colour list this Clear '
            . 'wrote (`brand_colors`).',
    )]
    #[ApiResponse(409, description: 'In use (`usage`), or part of a running replacement (`conflict`).')]
    public function clear(int $id, ?Request $request = null): Response
    {
        $slot = $id;
        if ($this->mutations === null) {
            return Response::notFound('No such brand colour.');
        }
        try {
            $actor = $request === null ? null : ActorHelper::uuidFromRequest($request);
            $written = $this->mutations->clear($slot, $actor);
        } catch (BrandColorInUse $e) {
            return Response::error('The brand colour is still in use.', 409, ['usage' => $e->usage]);
        } catch (PaletteConflict $e) {
            return Response::error($e->getMessage(), 409, ['conflict' => $e->getMessage()]);
        }
        // The list this Clear wrote, captured in its transaction (null when the id was not a colour).
        return Response::success(
            ['palette' => $this->schema?->paletteBlock(), 'brand_colors' => $written],
            'Brand colour cleared.',
        );
    }

    /** POST /v1/admin/appearance/palette/preview */
    #[ApiOperation(
        summary: 'Contrast of unsaved palette values',
        description: 'The contrast rows, swatches and resolved light and dark values for the Appearance '
            . 'page\'s unsaved accent, neutral, page ground and palette; absent fields read the saved ones. '
            . 'Nothing is saved. Requires `content.manage`.',
        tags: ['Thallo Settings'],
    )]
    #[ApiResponse(200, description: 'Rows, swatches and values.')]
    #[ApiResponse(422, description: 'An unknown accent, neutral or ground, or an invalid palette.')]
    public function preview(PalettePreviewData $input): Response
    {
        $errors = [];
        if ($input->theme_accent !== null && ThemeColors::normalizeSiteAccent($input->theme_accent) === null) {
            $errors['theme_accent'] = 'unknown accent color';
        }
        $neutral = $input->theme_neutral;
        if ($neutral !== null && $neutral !== 'custom' && ThemeColors::normalizeNeutral($neutral) === null) {
            $errors['theme_neutral'] = 'unknown neutral color';
        }
        $ground = $input->theme_background;
        if ($ground !== null && ThemeDesign::normalizeBackground($ground) === null) {
            $errors['theme_background'] = 'unknown page background';
        }
        $claim = $input->palette === null ? [] : PaletteSettings::previewClaim($input->palette);
        if ($claim === null) {
            $errors['palette'] = 'a palette is neutral_custom (six hex colours), dark_base (a neutral family) '
                . 'and brands (a list of {id, name, hex})';
        }
        if ($errors !== []) {
            return Response::validation($errors);
        }
        $effective = EffectivePalette::of(
            $input->theme_accent ?? $this->settings?->themeAccent() ?? ThemeColors::DEFAULT_ACCENT,
            $neutral ?? $this->settings?->themeNeutral() ?? ThemeColors::DEFAULT_NEUTRAL,
            $ground ?? $this->settings?->themeBackground() ?? 'plain',
            $this->palette?->preview($claim ?? []) ?? \Thallo\Contracts\Style\Palette::empty(),
        );
        return Response::success([
            'rows' => $effective->contrastRows(),
            'swatches' => $effective->swatches(),
            'values' => ['light' => $effective->values('light'), 'dark' => $effective->values('dark')],
        ], 'Palette preview.');
    }

    /** POST /v1/admin/appearance/palette/brand/{id}/replace */
    #[ApiOperation(
        summary: 'Replace a brand colour',
        description: 'Starts a job that rewrites every current document naming the slot (drafts, current '
            . 'publications — as new versions — regions, layouts, saved sections, style classes) to `to`, its '
            . 'text colour to `contrast_to` (or the destination\'s own pair), then clears the slot. History is '
            . 'never rewritten. 409 while the slot or a destination is part of a replacement; 422 for a '
            . 'destination the rules refuse. Requires `content.manage`.',
        tags: ['Thallo Settings'],
    )]
    #[ApiResponse(202, description: 'The job, started.')]
    #[ApiResponse(409, description: 'Part of a running replacement, or not configured.')]
    #[ApiResponse(422, description: 'A destination the rules refuse; or contrast_to is required.')]
    public function replace(ReplaceBrandData $input, int $id, ?Request $request = null): Response
    {
        $slot = $id;
        if ($this->replace === null) {
            return Response::notFound('No such brand colour.');
        }
        try {
            $id = $this->replace->start(
                $slot,
                $input->to,
                $input->contrast_to,
                $request === null ? null : ActorHelper::uuidFromRequest($request),
            );
        } catch (PaletteConflict $e) {
            return Response::error($e->getMessage(), 409, ['conflict' => $e->getMessage()]);
        } catch (\InvalidArgumentException $e) {
            return Response::validation(['to' => $e->getMessage()]);
        }
        return Response::success(['job' => $this->jobJson($id)], 'Replacement started.')->setStatusCode(202);
    }

    /** GET /v1/admin/appearance/palette/jobs */
    #[ApiOperation(
        summary: 'The running brand colour replacements',
        description: 'Every replacement that is running, interrupted or failed. Requires `content.manage`.',
        tags: ['Thallo Settings'],
    )]
    #[ApiResponse(200, description: 'The jobs.')]
    public function jobs(): Response
    {
        $jobs = array_map(self::present(...), $this->jobs?->active() ?? []);
        return Response::success(['jobs' => $jobs], 'Replacements retrieved.');
    }

    /** GET /v1/admin/appearance/palette/jobs/{id} */
    #[ApiOperation(
        summary: 'One brand colour replacement',
        description: 'Its progress and failures; a running job whose worker stopped reporting is '
            . '`interrupted`, and can be resumed. Requires `content.manage`.',
        tags: ['Thallo Settings'],
    )]
    #[ApiResponse(200, description: 'The job.')]
    #[ApiResponse(404, description: 'No such job.')]
    public function job(string $id): Response
    {
        $json = $this->jobJson($id);
        return $json === null ? Response::notFound('No such replacement.') : Response::success(['job' => $json]);
    }

    /** POST /v1/admin/appearance/palette/jobs/{id}/cancel */
    #[ApiOperation(
        summary: 'Cancel a brand colour replacement',
        description: 'Stops the job before its next write; what it already rewrote stays rewritten, and the '
            . 'slot stays configured. 409 when it already finished. Requires `content.manage`.',
        tags: ['Thallo Settings'],
    )]
    #[ApiResponse(200, description: 'Cancelled.')]
    #[ApiResponse(409, description: 'Already finished.')]
    public function cancel(string $id): Response
    {
        return $this->transitionResponse($id, fn () => $this->replace?->cancel($id), 'Replacement cancelled.');
    }

    /** POST /v1/admin/appearance/palette/jobs/{id}/resume */
    #[ApiOperation(
        summary: 'Resume a brand colour replacement',
        description: 'Runs a failed or interrupted job again. 409 for any other. Requires `content.manage`.',
        tags: ['Thallo Settings'],
    )]
    #[ApiResponse(200, description: 'Resumed.')]
    #[ApiResponse(409, description: 'Not failed or interrupted.')]
    public function resume(string $id): Response
    {
        return $this->transitionResponse($id, fn () => $this->replace?->resume($id), 'Replacement resumed.');
    }

    private function transitionResponse(string $id, callable $change, string $message): Response
    {
        if ($this->jobs?->find($id) === null) {
            return Response::notFound('No such replacement.');
        }
        try {
            $change();
        } catch (PaletteConflict $e) {
            return Response::error($e->getMessage(), 409, ['conflict' => $e->getMessage()]);
        }
        return Response::success(['job' => $this->jobJson($id)], $message);
    }

    /** @return array<string,mixed>|null */
    private function jobJson(string $id): ?array
    {
        $job = $this->jobs?->find($id);
        return $job === null ? null : self::present($job);
    }

    /** @return array<string,mixed> */
    private static function present(PaletteJob $job): array
    {
        return [
            'id' => $job->id,
            'slot' => $job->slot,
            'to' => $job->to,
            'contrast_to' => $job->contrastTo,
            'status' => PaletteReplaceService::displayStatus($job),
            'passes' => $job->passes,
            'work_items_total' => $job->total,
            'work_items_done' => $job->done,
            'work_items_failed' => $job->failed,
            'failure_report' => $job->failureReport,
            'created_at' => $job->createdAt,
            'finished_at' => $job->finishedAt,
        ];
    }
}

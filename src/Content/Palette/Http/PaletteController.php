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
use Thallo\Core\Content\Palette\PaletteMutations;
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
    ) {
    }

    /** GET /v1/admin/appearance/palette/brand/{slot}/usage */
    #[ApiOperation(
        summary: 'Where a brand colour is used',
        description: 'Blocking documents (drafts, current publications, regions, layouts, saved sections, style '
            . 'classes) and historical versions naming the slot or its text colour. Requires `content.manage`.',
        tags: ['Thallo Settings'],
    )]
    #[ApiResponse(200, description: 'The usage.')]
    #[ApiResponse(404, description: 'No such slot.')]
    public function usage(int $slot): Response
    {
        if (!in_array($slot, [1, 2, 3], true)) {
            return Response::notFound('No such brand colour.');
        }
        return Response::success(['usage' => $this->usage->of($slot)]);
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

    /** DELETE /v1/admin/appearance/palette/brand/{slot} */
    #[ApiOperation(
        summary: 'Clear a brand colour',
        description: 'Clears the slot when nothing blocking names it (drafts, current publications, regions, '
            . 'layouts, saved sections, style classes); historical versions never block. 409 with `usage` '
            . 'when something does, 409 with `conflict` while a replacement replaces or writes to the slot. '
            . 'Requires `content.manage`.',
        tags: ['Thallo Settings'],
    )]
    #[ApiResponse(200, description: 'Cleared; the style schema\'s palette block.')]
    #[ApiResponse(409, description: 'In use (`usage`), or part of a running replacement (`conflict`).')]
    public function clear(int $slot, ?Request $request = null): Response
    {
        if (!in_array($slot, [1, 2, 3], true) || $this->mutations === null) {
            return Response::notFound('No such brand colour.');
        }
        try {
            $this->mutations->clear($slot, $request === null ? null : ActorHelper::uuidFromRequest($request));
        } catch (BrandColorInUse $e) {
            return Response::error('The brand colour is still in use.', 409, ['usage' => $e->usage]);
        } catch (PaletteConflict $e) {
            return Response::error($e->getMessage(), 409, ['conflict' => $e->getMessage()]);
        }
        return Response::success(['palette' => $this->schema?->paletteBlock()], 'Brand colour cleared.');
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
                . 'and brands (slots 1–3, each a name and a hex colour)';
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
}

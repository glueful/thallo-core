<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Palette\Http;

use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Content\Palette\BrandColorUsage;
use Thallo\Core\Content\Palette\PaletteHistoryExpired;
use Thallo\Core\Content\Palette\PaletteReplacements;

/**
 * The palette's admin endpoints (custom palette spec §4, §5): where a brand colour is used, clearing
 * and replacing it, and the contrast preview. Usage and every change need content.manage.
 */
final class PaletteController
{
    public function __construct(
        private readonly BrandColorUsage $usage,
        private readonly PaletteReplacements $replacements,
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
}

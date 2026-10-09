<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Palette\Http;

use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Thallo\Core\Content\Palette\BrandColorUsage;

/**
 * The palette's admin endpoints (custom palette spec §4, §5): where a brand colour is used, clearing
 * and replacing it, and the contrast preview. Usage and every change need content.manage.
 */
final class PaletteController
{
    public function __construct(private readonly BrandColorUsage $usage)
    {
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
}

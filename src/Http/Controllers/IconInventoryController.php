<?php

declare(strict_types=1);

namespace Thallo\Core\Http\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Response;
use Thallo\Render\Templates\IconInventory;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The vendored icon inventory for the admin icon picker (icon-picker spec §1):
 * names come from the render pack's shipped SVG directories, so the picker can
 * only offer icons icon() can actually render. Requires `content.view`.
 */
final class IconInventoryController
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    /** GET /v1/admin/icons?set=lucide|brands */
    #[ApiOperation(
        summary: 'List vendored icons',
        description: 'Bare icon names from the render pack\'s vendored set (lucide|brands), sorted. '
            . 'The icon picker\'s source of truth — parity with icon() by construction. '
            . 'Requires `content.view`.',
        tags: ['Thallo Icons'],
    )]
    #[ApiResponse(200, description: 'Sorted icon names.')]
    #[ApiResponse(409, description: 'Render pack unavailable.')]
    #[ApiResponse(422, description: 'Unknown set.')]
    public function index(Request $request): Response
    {
        $container = container($this->context);
        if (!$container->has(IconInventory::class)) {
            return Response::error('Icons unavailable: the render pack is not active.', 409);
        }
        $set = (string) $request->query->get('set', 'lucide');
        $inventory = $container->get(IconInventory::class);
        $names = $inventory->names($set);
        if ($names === null) {
            return Response::validation(['set' => 'must be one of: lucide, brands']);
        }
        $payload = ['icons' => $names];
        // include=svg ships the raw vendored markup for PREVIEWS (brands: the
        // admin's icon pipeline can't render them; ~30KB for the curated set).
        if ($request->query->get('include') === 'svg') {
            $payload['svgs'] = $inventory->svgs($set) ?? (object) [];
        }
        return Response::success($payload, 'Icons retrieved.');
    }
}

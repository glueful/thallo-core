<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\Controllers;

use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Content\Http\DTOs\Responses\Patterns\PatternListData;
use Thallo\Core\Content\Patterns\PatternLibrary;

/**
 * The section and page library, for the designer's Blocks tab. Read-only: a pattern is a tree of
 * ordinary blocks, and inserting one is an ordinary edit of the page, judged and saved as such.
 * With `surface` and `target`, the layout editor's library for that layout instead.
 */
final class PatternController
{
    public function __construct(private readonly PatternLibrary $library)
    {
    }

    #[ApiOperation(
        summary: 'List the section and page library',
        tags: ['Thallo Admin'],
        description: 'Ready-made sections and starter pages, as block trees without ids. A pattern '
            . 'that uses a block type this site has switched off is not listed. With the query '
            . '`surface` and `target`, the layout editor\'s library for that layout instead: the '
            . 'surface\'s sections and templates built and validated for the target (a template with '
            . 'its Frame `settings`), then the sections saved for the surface; nothing for a target that '
            . 'cannot have a layout.',
    )]
    #[ApiResponse(200, schema: PatternListData::class, description: 'Sections, then pages.')]
    #[ApiResponse(422, description: 'A `surface` this site has no layout for.')]
    public function index(Request $request): Response
    {
        $surface = $request->query->get('surface');
        if (!is_string($surface) || $surface === '') {
            return Response::success(['patterns' => $this->library->all()], 'Patterns retrieved.');
        }
        try {
            $patterns = $this->library->forLayout($surface, (string) $request->query->get('target', ''));
        } catch (\InvalidArgumentException $e) {
            return Response::validation(['surface' => $e->getMessage()]);
        }
        return Response::success(['patterns' => $patterns], 'Patterns retrieved.');
    }
}

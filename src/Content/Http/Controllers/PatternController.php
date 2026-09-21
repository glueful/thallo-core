<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\Controllers;

use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Thallo\Core\Content\Http\DTOs\Responses\Patterns\PatternListData;
use Thallo\Core\Content\Patterns\PatternLibrary;

/**
 * The section and page library, for the designer's Blocks tab. Read-only: a pattern is a tree of
 * ordinary blocks, and inserting one is an ordinary edit of the page, judged and saved as such.
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
            . 'that uses a block type this site has switched off is not listed.',
    )]
    #[ApiResponse(200, schema: PatternListData::class, description: 'Sections, then pages.')]
    public function index(): Response
    {
        return Response::success(['patterns' => $this->library->all()], 'Patterns retrieved.');
    }
}

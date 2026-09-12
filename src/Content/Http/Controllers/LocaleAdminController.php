<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\Controllers;

use Thallo\Core\Content\Http\DTOs\Responses\Entries\LocaleUsageData;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Http\DTOs\ErrorResponse;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

final class LocaleAdminController
{
    public function __construct(private readonly EntryRepository $entries)
    {
    }

    #[ApiOperation(
        summary: 'Get locale content usage counts',
        description: 'Returns the number of published and draft entries that exist in the given locale. '
            . 'Use this before disabling a locale to warn when published content would be hidden. '
            . 'Requires the `content.manage` permission.',
        tags: ['Thallo Admin'],
    )]
    #[ApiResponse(200, schema: LocaleUsageData::class, description: 'Published and draft entry counts for the locale.')]
    #[ApiResponse(401, schema: ErrorResponse::class, envelope: false, description: 'Not authenticated.')]
    #[ApiResponse(
        403,
        schema: ErrorResponse::class,
        envelope: false,
        description: 'Missing content.manage permission.'
    )]
    public function usage(Request $request, string $locale): Response
    {
        return Response::success($this->entries->localeUsage($locale), 'Locale usage retrieved.');
    }
}

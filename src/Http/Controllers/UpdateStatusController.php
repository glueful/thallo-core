<?php

declare(strict_types=1);

namespace Thallo\Core\Http\Controllers;

use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Thallo\Core\Http\DTOs\Responses\UpdateStatusResultData;
use Thallo\Core\Updates\UpdateChecker;

/**
 * The update notice for administrators (DISTRIBUTION.md decision 11, amended): what this install
 * runs, the newest published glueful/thallo-core the install may move to, and the release notes.
 * Read-only and operator-only — never on the unauthenticated /admin/config, where the installed
 * version would be a fingerprint. The upgrade itself is a server-side command.
 */
final class UpdateStatusController
{
    public function __construct(private readonly UpdateChecker $checker)
    {
    }

    #[ApiOperation(
        summary: 'Update status',
        description: 'The installed glueful/thallo-core version, the newest published version this install '
            . 'may move to (from the daily Packagist check), and the release notes link. Read-only; '
            . 'requires `system.access`. The upgrade is `composer update && php glueful thallo:provision`.',
        tags: ['Utilities'],
    )]
    #[ApiResponse(200, schema: UpdateStatusResultData::class, description: 'Update status.')]
    public function show(): Response
    {
        return Response::success(['update' => $this->checker->status()->toArray()], 'Update status retrieved.');
    }
}

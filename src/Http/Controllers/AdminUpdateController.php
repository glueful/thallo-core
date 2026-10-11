<?php

declare(strict_types=1);

namespace Thallo\Core\Http\Controllers;

use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Http\DTOs\Responses\UpdatePreflightResultData;
use Thallo\Core\Http\DTOs\Responses\UpdateStartResultData;
use Thallo\Core\Support\ActorHelper;
use Thallo\Core\Updater\OperationStore;
use Thallo\Core\Updater\UpdateRefused;
use Thallo\Core\Updates\UpdatePreflight;

/**
 * Updating from the admin (docs/upgrading.md): PHP-FPM only checks, files the request and cancels
 * it before a runner claims it. It never changes code — the update runner, a separate process the
 * owner schedules as the code owner, does that. Owner only (system.config).
 */
final class AdminUpdateController
{
    public function __construct(
        private readonly UpdatePreflight $preflight,
        private readonly OperationStore $operations,
    ) {
    }

    #[ApiOperation(
        summary: 'Update preflight',
        description: 'Whether updating from the admin can run now, the version it would install, and every '
            . 'reason it cannot. Requires the system.config permission.',
        tags: ['Utilities'],
    )]
    #[ApiResponse(200, schema: UpdatePreflightResultData::class, description: 'Update preflight.')]
    public function preflight(): Response
    {
        return Response::success(['preflight' => $this->preflight->check()], 'Update preflight.');
    }

    #[ApiOperation(
        summary: 'Start an update',
        description: 'Files a request for the update runner to install the target version. Answers 202 with the '
            . 'operation id and a one-time token for the /_update page; 409 with the reasons when preflight is '
            . 'not ready or an update is already open. Requires the system.config permission.',
        tags: ['Utilities'],
    )]
    #[ApiResponse(202, schema: UpdateStartResultData::class, description: 'Update requested.')]
    public function start(Request $request): Response
    {
        $check = $this->preflight->check();
        if (!$check['ready'] || $check['target'] === null || $check['current'] === null) {
            return Response::error('Updating from the admin is unavailable.', Response::HTTP_CONFLICT, [
                'reasons' => $check['reasons'] !== [] ? $check['reasons'] : ['no_update'],
            ]);
        }
        try {
            [$op, $token] = $this->operations->start(
                $check['target'],
                $check['current'],
                ActorHelper::uuidFromRequest($request) ?? 'unknown',
            );
        } catch (UpdateRefused $e) {
            return Response::error('An update is already open.', Response::HTTP_CONFLICT, ['reasons' => [$e->reason]]);
        }
        $response = Response::success(['update' => ['id' => $op['id'], 'token' => $token]], 'Update requested.');
        $response->setStatusCode(202);
        return $response;
    }

    #[ApiOperation(
        summary: 'Cancel a requested update',
        description: 'Cancels an update the runner has not claimed yet; 409 once it has. Requires the '
            . 'system.config permission.',
        tags: ['Utilities'],
    )]
    #[ApiResponse(200, description: 'Update cancelled.')]
    public function cancel(): Response
    {
        $current = $this->operations->current();
        if ($current === null || !$this->operations->cancel((string) $current['id'])) {
            return Response::error('There is no requested update to cancel.', Response::HTTP_CONFLICT);
        }
        return Response::success(['cancelled' => true], 'Update cancelled.');
    }
}

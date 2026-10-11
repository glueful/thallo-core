<?php

declare(strict_types=1);

namespace Thallo\Core\Http\Controllers;

use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Http\DTOs\Responses\UpdatePreflightResultData;
use Thallo\Core\Http\DTOs\Responses\UpdateStartResultData;
use Thallo\Core\Http\DTOs\StartUpdateData;
use Thallo\Core\Support\ActorHelper;
use Thallo\Core\Updater\AtomicFile;
use Thallo\Core\Updater\Paths;
use Thallo\Core\Updates\UpdatePreflight;

/**
 * Updating from the admin (docs/upgrading.md): PHP-FPM only checks, files a request in the inbox and
 * cancels it before the runner claims it (spec §16.2). It never changes code and never writes what
 * the runner trusts — the update runner, a separate process the owner schedules as the code owner,
 * validates the request against its own records. Owner only (system.config).
 */
final class AdminUpdateController
{
    public function __construct(
        private readonly UpdatePreflight $preflight,
        private readonly Paths $paths,
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
        description: 'Files a request for the update runner to install `target`, the version the dialog showed. '
            . 'Answers 202 with the operation id and a one-time token for the /_update page; 409 with the reasons '
            . 'when preflight is not ready or an update is already open, and 409 `target_changed` when preflight '
            . 'now offers another version. Requires the system.config permission.',
        tags: ['Utilities'],
    )]
    #[ApiResponse(202, schema: UpdateStartResultData::class, description: 'Update requested.')]
    public function start(StartUpdateData $input, Request $request): Response
    {
        $check = $this->preflight->check();
        if (!$check['ready'] || $check['target'] === null || $check['current'] === null) {
            return Response::error('Updating from the admin is unavailable.', Response::HTTP_CONFLICT, [
                'reasons' => $check['reasons'] !== [] ? $check['reasons'] : ['no_update'],
            ]);
        }
        if ($input->target !== $check['target']) {
            // The dialog showed one version; a newer one appeared since. Install only what was confirmed.
            return Response::error('The version on offer changed.', Response::HTTP_CONFLICT, [
                'reasons' => ['target_changed'],
            ]);
        }
        $token = bin2hex(random_bytes(32));
        $by = ActorHelper::uuidFromRequest($request) ?? 'unknown';
        AtomicFile::writeJson($this->paths->startRequest(), [
            'target' => $check['target'],
            'from' => $check['current'],
            'requested_by' => preg_match('/^[A-Za-z0-9._@-]{1,64}$/', $by) === 1 ? $by : 'unknown',
            'requested_at' => gmdate('c'),
            'token_hash' => hash('sha256', $token),
        ], 0660);
        $response = Response::success(['update' => ['id' => 'requested', 'token' => $token]], 'Update requested.');
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
        $file = $this->paths->startRequest();
        if (!is_file($file) || !@unlink($file)) {
            return Response::error('There is no requested update to cancel.', Response::HTTP_CONFLICT);
        }
        return Response::success(['cancelled' => true], 'Update cancelled.');
    }
}

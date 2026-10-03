<?php

declare(strict_types=1);

namespace Thallo\Core\Http\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Routing\RouteCache;
use Glueful\Routing\RouteManifest;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Capabilities\Activation\ActivationInProgress;
use Thallo\Core\Capabilities\Activation\ActivationOutcome;
use Thallo\Core\Capabilities\Activation\ActivationRunner;
use Thallo\Core\Capabilities\Activation\ActivationStatus;
use Thallo\Core\Capabilities\Activation\ActivationStore;
use Thallo\Core\Capabilities\Activation\ActivationSuperseded;
use Thallo\Core\Capabilities\FeatureManagementPolicy;
use Thallo\Core\Http\DTOs\ActivationGenerationData;
use Thallo\Core\Support\ActorHelper;

/**
 * Turning on a feature whose engine needs a fresh boot (feature activation spec §3.2–3.3).
 *
 *  - start: starts the activation, or joins the open one, and runs it up to the boot boundary.
 *    `continue: true` asks the caller to send a continue, which a fresh request handles.
 *  - continue: resumes the named generation in this (fresh) request; the runner enforces the
 *    boundary itself. A generation that is no longer current is refused (409 `superseded`), as is
 *    a continue while another runner holds the operation (409 `in_progress`).
 *  - cancel: supersedes the named generation and publishes the feature off in one write; a
 *    delayed cancel of an older generation changes nothing (409 `superseded`).
 */
class CapabilityActivationController
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ActivationStore $store,
        private readonly ActivationRunner $runner,
    ) {
    }

    /** POST /v1/admin/capabilities/{id}/activation */
    #[ApiOperation(
        summary: 'Turn on a feature',
        description: 'Starts the feature\'s activation (or joins the open one) and runs it up to the '
            . 'point that needs a freshly booted request. `continue: true` means send a continue. '
            . 'Requires the `system.access` permission.',
        tags: ['Capabilities'],
    )]
    #[ApiResponse(202, description: 'The activation record, and whether to continue.')]
    #[ApiResponse(404, description: 'Not a feature that turns on through activation.')]
    #[ApiResponse(409, description: 'Another request is running this activation (`in_progress`).')]
    public function start(Request $request, string $id): Response
    {
        if (($refused = $this->refuseMisconfigured($id)) !== null) {
            return $refused;
        }
        if (!$this->isActivationCapability($id)) {
            return Response::notFound("“{$id}” doesn't turn on through activation.");
        }
        $actor = ActorHelper::uuidFromRequest($request) ?? 'admin-api';
        // The first turn-on creates its row in its own committed transaction (after any workspace
        // seed in flight), then starts in a second.
        $this->store->initializeRow($id);
        $record = $this->store->startOrJoin($id, $actor);
        return $this->respond(fn (): ActivationOutcome => $this->runner->run($id, $record->generation, false), 202);
    }

    /** POST /v1/admin/capabilities/{id}/activation/continue */
    #[ApiOperation(
        summary: 'Continue turning on a feature',
        description: 'Resumes the named activation generation from its next step. Requires the '
            . '`system.access` permission.',
        tags: ['Capabilities'],
    )]
    #[ApiResponse(200, description: 'The activation record, and whether to continue again.')]
    #[ApiResponse(404, description: 'Not a feature that turns on through activation.')]
    #[ApiResponse(409, description: 'A newer decision exists (`superseded`), or it is running (`in_progress`).')]
    public function continue(ActivationGenerationData $input, Request $request, string $id): Response
    {
        if (($refused = $this->refuseMisconfigured($id)) !== null) {
            return $refused;
        }
        if (!$this->isActivationCapability($id)) {
            return Response::notFound("“{$id}” doesn't turn on through activation.");
        }
        return $this->respond(fn (): ActivationOutcome => $this->runner->run($id, $input->generation, true), 200);
    }

    /** DELETE /v1/admin/capabilities/{id}/activation */
    #[ApiOperation(
        summary: 'Cancel turning on a feature',
        description: 'Supersedes the named activation generation and stores the feature off. Requires '
            . 'the `system.access` permission.',
        tags: ['Capabilities'],
    )]
    #[ApiResponse(200, description: 'The superseded activation record.')]
    #[ApiResponse(404, description: 'Not a feature that turns on through activation.')]
    #[ApiResponse(409, description: 'That generation is no longer current (`superseded`); nothing changed.')]
    public function cancel(ActivationGenerationData $input, Request $request, string $id): Response
    {
        if (($refused = $this->refuseMisconfigured($id)) !== null) {
            return $refused;
        }
        if (!$this->isActivationCapability($id)) {
            return Response::notFound("“{$id}” doesn't turn on through activation.");
        }
        $actor = ActorHelper::uuidFromRequest($request) ?? 'admin-api';
        try {
            $this->store->supersede($id, $actor, expectedGeneration: $input->generation);
        } catch (ActivationSuperseded $e) {
            return Response::error($e->getMessage(), 409, ['reason' => 'superseded']);
        }
        $this->clearCompiledRouteState();
        return Response::success(['activation' => $this->store->find($id)?->toArray()], 'Activation cancelled.');
    }

    /** @param \Closure(): ActivationOutcome $run */
    private function respond(\Closure $run, int $status): Response
    {
        try {
            $outcome = $run();
        } catch (ActivationSuperseded $e) {
            return Response::error($e->getMessage(), 409, ['reason' => 'superseded']);
        } catch (ActivationInProgress $e) {
            return Response::error($e->getMessage(), 409, ['reason' => 'in_progress']);
        }
        if ($outcome->record->status === ActivationStatus::SUCCEEDED) {
            // Interim until the route table is keyed by the capability state version: the
            // compiled table was built with the feature off.
            $this->clearCompiledRouteState();
        }
        $response = Response::success(
            ['activation' => $outcome->record->toArray(), 'continue' => $outcome->needsBoot],
            $outcome->needsBoot ? 'Continue in a new request.' : 'Activation updated.',
        );
        $response->setStatusCode($status);
        return $response;
    }

    /** A misconfigured capability can't be started, continued or cancelled (409 `misconfigured`). */
    private function refuseMisconfigured(string $id): ?Response
    {
        $why = app($this->context, FeatureManagementPolicy::class)->misconfiguration($id);
        return $why === null ? null : Response::error($why, 409, ['reason' => 'misconfigured']);
    }

    private function isActivationCapability(string $id): bool
    {
        return in_array($id, app($this->context, FeatureManagementPolicy::class)->activationCapabilities(), true);
    }

    /** Overridable seam: the compiled route table is rebuilt on the next request. */
    protected function clearCompiledRouteState(): void
    {
        (new RouteCache($this->context))->clear();
        RouteManifest::reset();
    }
}

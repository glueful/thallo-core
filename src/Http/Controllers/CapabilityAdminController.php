<?php

declare(strict_types=1);

namespace Thallo\Core\Http\Controllers;

use Thallo\Core\Capabilities\CapabilityStateStore;
use Thallo\Core\Http\DTOs\Responses\CapabilityListData;
use Thallo\Core\Http\DTOs\UpdateCapabilityStateData;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Routing\RouteCache;
use Glueful\Routing\RouteManifest;
use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\CapabilityRegistry;

/**
 * Capabilities for the admin SPA.
 *
 *  - index: the ENABLED capabilities — read-only discovery, auth-only by design (a workspace
 *    owner must see which modules exist without operator rights). Response byte-compatible with
 *    the pre-switchboard feed.
 *  - manage/update: the operator switchboard (`system.access`): every REGISTERED capability
 *    with its requested/availability/effective triple, and the requested-state flip persisted
 *    through the one system-scoped CapabilityStateStore. Disable is always allowed; enable
 *    refuses 409 while the owning engine cannot back the capability. An effective flip clears
 *    the compiled route cache — capability gates decide route REGISTRATION at boot, and the
 *    cache is keyed by route-file signatures a flip never touches.
 */
class CapabilityAdminController
{
    public function __construct(
        private readonly CapabilityRegistry $capabilities,
        private readonly CapabilityStateStore $state,
        private readonly ApplicationContext $context,
    ) {
    }

    /** GET /v1/admin/capabilities */
    #[ApiOperation(
        summary: 'List enabled capabilities',
        description: 'Capabilities provided by installed packs and not disabled by the '
            . 'thallo.capabilities switchboard. Requires the `system.access` permission.',
        tags: ['Capabilities'],
    )]
    #[ApiResponse(200, schema: CapabilityListData::class, description: 'Enabled capabilities.')]
    public function index(): Response
    {
        $items = array_map(
            static fn (Capability $c): array => [
                'id' => $c->id,
                'label' => $c->label,
                'description' => $c->description,
                'requires' => $c->requires,
            ],
            $this->capabilities->enabled(),
        );

        return Response::success(['capabilities' => array_values($items)], 'Capabilities retrieved.');
    }

    /** GET /v1/admin/capabilities/manage — the operator switchboard view. */
    #[ApiOperation(
        summary: 'List all registered capabilities with switchboard state',
        description: 'Every registered capability with requested, availability (reason/remedy) '
            . 'and effective state. Operator-only: requires the `system.access` permission.',
        tags: ['Capabilities'],
    )]
    #[ApiResponse(200, description: 'All registered capabilities with management state.')]
    public function manage(): Response
    {
        $items = [];
        foreach ($this->capabilities->all() as $capability) {
            $availability = $this->capabilities->availability($capability->id);
            $items[] = [
                'id' => $capability->id,
                'label' => $capability->label,
                'description' => $capability->description,
                'requires' => $capability->requires,
                'owning_package' => $capability->owningPackage,
                'requested' => $this->capabilities->isRequestedEnabled($capability->id),
                'available' => $availability->available,
                'reason' => $availability->reason,
                'remedy' => $availability->remedy,
                'effective' => $this->capabilities->isEnabled($capability->id),
            ];
        }
        usort($items, static fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));

        return Response::success(['capabilities' => $items], 'Capability management state retrieved.');
    }

    /** PUT /v1/admin/capabilities/{id} — flip the requested state on the switchboard. */
    #[ApiOperation(
        summary: 'Enable or disable a capability',
        description: 'Persists the requested state in the system-scoped switchboard. Disable is '
            . 'always allowed; enable refuses 409 while the owning engine cannot back the '
            . 'capability. Operator-only: requires the `system.access` permission.',
        tags: ['Capabilities'],
    )]
    #[ApiResponse(200, description: 'Requested state persisted (read back before reporting).')]
    #[ApiResponse(404, description: 'No such registered capability.')]
    #[ApiResponse(409, description: 'Enable refused: the owning engine cannot back it.')]
    public function update(string $id, UpdateCapabilityStateData $input): Response
    {
        // The id must EXACTLY match a registered capability: request text never becomes an
        // arbitrary system key.
        $registered = false;
        foreach ($this->capabilities->all() as $capability) {
            if ($capability->id === $id) {
                $registered = true;
                break;
            }
        }
        if (!$registered) {
            return Response::notFound("No registered capability named “{$id}”.");
        }

        $availability = $this->capabilities->availability($id);
        if ($input->enabled && !$availability->available) {
            return Response::error(
                "Cannot enable {$id}: " . ($availability->reason ?? 'its owning engine is unavailable.'),
                409,
                ['reason' => $availability->reason, 'remedy' => $availability->remedy],
            );
        }

        $effectiveBefore = $this->capabilities->isEnabled($id);
        try {
            $this->state->put($id, $input->enabled);
        } catch (\Throwable $e) {
            return Response::error('Capability state write failed: ' . $e->getMessage(), 500);
        }

        // This boot's registry memo still answers with the OLD state (by design — the flip
        // lands on the next request), so effective-after is computed, not re-read.
        $effectiveAfter = $input->enabled && $availability->available;
        if ($effectiveAfter !== $effectiveBefore) {
            // Capability gates decide route registration at boot; the compiled route cache is
            // keyed by route-file signatures, which a flip never changes. Same idiom as the
            // Settings › General search toggle.
            $this->clearCompiledRouteState();
        }

        return Response::success([
            'id' => $id,
            'requested' => $input->enabled,
            'available' => $availability->available,
            'effective' => $effectiveAfter,
        ], $input->enabled ? 'Capability enabled.' : 'Capability disabled.');
    }

    /** Overridable seam so tests can observe the purge without touching real compiled state. */
    protected function clearCompiledRouteState(): void
    {
        (new RouteCache($this->context))->clear();
        RouteManifest::reset();
    }
}

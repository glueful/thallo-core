<?php

declare(strict_types=1);

namespace Thallo\Core\Http\Controllers;

use Thallo\Core\Capabilities\Activation\ActivationStore;
use Thallo\Core\Capabilities\Activation\EngineActivation;
use Thallo\Core\Capabilities\CapabilityStateStore;
use Thallo\Core\Capabilities\FeatureManagementPolicy;
use Thallo\Core\Support\ActorHelper;
use Glueful\Extensions\ExtensionManager;
use Symfony\Component\HttpFoundation\Request;
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
                ...$this->activationFields($capability),
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
    public function update(string $id, UpdateCapabilityStateData $input, ?Request $request = null): Response
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

        // A feature with an activation flow is never written on directly (it would skip the
        // engine, its blocks and its grants), and turning it off supersedes any open activation
        // and stores it off in one write.
        if ($this->policy()->capabilityManagement($id) === 'activation') {
            if ($input->enabled) {
                return Response::error(
                    "{$id} turns on through its activation: POST /v1/admin/capabilities/{$id}/activation.",
                    409,
                    ['reason' => 'use_activation'],
                );
            }
            return $this->turnOffActivationCapability($id, $request);
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

    private function turnOffActivationCapability(string $id, ?Request $request): Response
    {
        $effectiveBefore = $this->capabilities->isEnabled($id);
        $actor = ($request !== null ? ActorHelper::uuidFromRequest($request) : null) ?? 'admin-api';
        try {
            $this->container()->get(ActivationStore::class)->supersede($id, $actor);
        } catch (\Throwable $e) {
            return Response::error('Capability state write failed: ' . $e->getMessage(), 500);
        }
        if ($effectiveBefore) {
            $this->clearCompiledRouteState();
        }
        return Response::success([
            'id' => $id,
            'requested' => false,
            'available' => $this->capabilities->availability($id)->available,
            'effective' => false,
        ], 'Capability disabled.');
    }

    /**
     * How Extensions › Capabilities switches this capability (its declared mode, with an external
     * flow's destination and an activation's copy), and for an activation capability its open or
     * last activation, whether application files can be written, and whether its engine is loaded.
     *
     * @return array{management: string, destination: ?array{path: string, label: string},
     *     copy: ?array{turn_on: ?string, turn_off: ?string, links: list<array{label: string, to: string}>},
     *     activation: ?array<string, mixed>, application_files_writable: ?bool, engine_enabled: ?bool}
     */
    private function activationFields(Capability $capability): array
    {
        $id = $capability->id;
        $policy = $this->policy();
        $declared = [
            'management' => $policy->capabilityManagement($id),
            'destination' => $capability->destination === null
                ? null
                : ['path' => $capability->destination->path, 'label' => $capability->destination->label],
            'copy' => $capability->copy === null
                ? null
                : [
                    'turn_on' => $capability->copy->turnOn,
                    'turn_off' => $capability->copy->turnOff,
                    'links' => $capability->copy->links,
                ],
        ];
        $engine = $policy->engineOf($id);
        if ($declared['management'] !== 'activation' || $engine === null) {
            return $declared + [
                'activation' => null,
                'application_files_writable' => null,
                'engine_enabled' => null,
            ];
        }
        $record = $this->container()->get(ActivationStore::class)->find($id);
        return $declared + [
            'activation' => $record !== null && $record->generation > 0 ? $record->toArray() : null,
            'application_files_writable' => $this->container()->get(EngineActivation::class)
                ->applicationFilesWritable(),
            'engine_enabled' => $this->container()->get(ExtensionManager::class)->hasProvider($engine['provider']),
        ];
    }

    private function policy(): FeatureManagementPolicy
    {
        return $this->container()->get(FeatureManagementPolicy::class);
    }

    private function container(): \Psr\Container\ContainerInterface
    {
        return $this->context->getContainer();
    }

    /** Overridable seam so tests can observe the purge without touching real compiled state. */
    protected function clearCompiledRouteState(): void
    {
        (new RouteCache($this->context))->clear();
        RouteManifest::reset();
    }
}

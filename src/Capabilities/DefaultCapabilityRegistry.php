<?php

declare(strict_types=1);

namespace Thallo\Core\Capabilities;

use Thallo\Contracts\Capability\Capability;
use Thallo\Contracts\Capability\CapabilityAvailability;
use Thallo\Contracts\Capability\CapabilityAvailabilityResolver;
use Thallo\Contracts\Capability\CapabilityRegistry;

/**
 * In-memory capability registry. Packs register their Capability during boot; the host's
 * switchboard ($overrides, the `thallo.capabilities` config map keyed by full capability id, or
 * the live store) decides which installed capabilities are REQUESTED (`false` => disabled,
 * `true` => requested), and the owner-availability resolver decides which are AVAILABLE.
 * An UNTOUCHED switch (no explicit answer anywhere) FOLLOWS THE ENGINE: it reads on when the
 * owning engine is available and off when it is not — so a fresh install shows a tier-2 pack
 * (Commerce) or the enforcement-owned tenancy switch as plainly Off rather than
 * "on, engine unavailable", and enabling the engine (extensions browser, Workspaces flow) is
 * the opt-in that turns the capability on. EFFECTIVE enabled — what isEnabled()/enabled()
 * report and every gate consumes — is requested AND available (spec B3), so a capability whose
 * engine cannot back it fails closed everywhere.
 *
 * Availability is memoized for the registry's lifetime (one request or CLI boot): repeated
 * provider gates must not repeat ledger queries. Direct construction without a resolver stays
 * supported for tests/hosts — ownerless capabilities remain available, while an owned one
 * fails closed with an explicit "resolver unavailable" reason.
 */
final class DefaultCapabilityRegistry implements CapabilityRegistry
{
    /** @var array<string,Capability> */
    private array $capabilities = [];

    /** @var array<string,CapabilityAvailability> memoized per registry lifetime */
    private array $availability = [];

    /** @var array<string,bool> memoized per registry lifetime */
    private array $requested = [];

    /**
     * @param array<string,bool> $overrides Full-capability-id => enabled flag.
     * @param (\Closure(string): ?bool)|null $requestedState Live requested-state source (the
     *        switchboard); when set it REPLACES the static overrides map. `null` means "no
     *        explicit answer" and the switch follows the engine. Memoized per registry
     *        lifetime, so repeated gates cost one lookup per capability per boot.
     */
    public function __construct(
        private readonly array $overrides = [],
        private readonly ?CapabilityAvailabilityResolver $resolver = null,
        private readonly ?\Closure $requestedState = null,
    ) {
    }

    public function register(Capability $capability): void
    {
        $this->capabilities[$capability->id] = $capability;
        // A verdict cached before registration (or for a replaced declaration) is stale.
        unset($this->availability[$capability->id]);
    }

    /** @return list<Capability> */
    public function all(): array
    {
        return array_values($this->capabilities);
    }

    /** @return list<Capability> */
    public function enabled(): array
    {
        return array_values(array_filter(
            $this->capabilities,
            fn (Capability $c): bool => $this->isEnabled($c->id),
        ));
    }

    public function isEnabled(string $id): bool
    {
        return $this->isRequestedEnabled($id) && $this->availability($id)->available;
    }

    public function isRequestedEnabled(string $id): bool
    {
        if (!isset($this->capabilities[$id])) {
            return false;
        }
        return $this->requested[$id] ??= $this->resolveRequested($id);
    }

    private function resolveRequested(string $id): bool
    {
        $explicit = $this->requestedState !== null
            ? ($this->requestedState)($id)
            : ($this->overrides[$id] ?? null);
        if ($explicit !== null) {
            return $explicit === true;
        }
        // Untouched switch: follow the engine (see the class docblock).
        return $this->availability($id)->available;
    }

    public function availability(string $id): CapabilityAvailability
    {
        return $this->availability[$id] ??= $this->resolveAvailability($id);
    }

    private function resolveAvailability(string $id): CapabilityAvailability
    {
        $capability = $this->capabilities[$id] ?? null;
        if ($capability === null) {
            return CapabilityAvailability::unavailable("Capability {$id} is not registered.");
        }
        if ($this->resolver === null) {
            return $capability->owningPackage === null
                ? CapabilityAvailability::available()
                : CapabilityAvailability::unavailable(
                    "Owner availability resolver unavailable — cannot verify {$capability->owningPackage}."
                );
        }
        return $this->resolver->resolve($capability);
    }
}

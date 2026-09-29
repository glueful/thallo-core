<?php

declare(strict_types=1);

namespace Thallo\Core\Payments\Tenancy;

/**
 * What {@see PaymentTenancyAdoption} found — and, after an apply, did — for one default workspace.
 *
 * `counts` is per table: unassigned rows (tenant ''), the default workspace's, every other
 * workspace's. `collisions` are unassigned rows whose workspace-scoped unique key the default
 * workspace already holds; `ambiguous` are unassigned rows a related row places in ANOTHER
 * workspace. Either refuses the move. `duplicates` are orders (any payable) with more than one live
 * or collected intent in one workspace — reported for reconciliation with the gateway, never
 * resolved, and never a reason to refuse. `constrained` lists the tables that refuse an unassigned
 * row.
 */
final class PaymentAdoptionReport
{
    /**
     * @param array<string, array{unassigned: int, default: int, other: int}> $counts
     * @param list<array{table: string, index: string, key: array<string, mixed>, unassigned_id: int,
     *     existing_id: int}> $collisions
     * @param list<array{table: string, id: int, uuid: string, workspace: string, reason: string}> $ambiguous
     * @param list<array{payable_type: string, payable_id: string, workspace: string,
     *     intents: list<array<string, mixed>>, settled_payments: int}> $duplicates
     * @param list<string> $constrained
     * @param array<string, int> $moved
     */
    public function __construct(
        public readonly string $tenantUuid,
        public readonly array $counts,
        public readonly array $collisions,
        public readonly array $ambiguous,
        public readonly array $duplicates,
        public readonly array $constrained,
        public readonly array $moved = [],
    ) {
    }

    public function refused(): bool
    {
        return $this->collisions !== [] || $this->ambiguous !== [];
    }

    public function unassignedTotal(): int
    {
        return array_sum(array_column($this->counts, 'unassigned'));
    }

    /** @param array<string, int> $moved */
    public function withMoved(array $moved, array $constrained): self
    {
        return new self(
            $this->tenantUuid,
            $this->counts,
            $this->collisions,
            $this->ambiguous,
            $this->duplicates,
            $constrained,
            $moved,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tenant_uuid' => $this->tenantUuid,
            'counts' => $this->counts,
            'collisions' => $this->collisions,
            'ambiguous' => $this->ambiguous,
            'duplicates' => $this->duplicates,
            'constrained' => $this->constrained,
            'moved' => $this->moved,
            'refused' => $this->refused(),
        ];
    }
}

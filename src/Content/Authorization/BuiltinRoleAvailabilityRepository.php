<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Authorization;

use Glueful\Database\Connection;

/**
 * Per-workspace availability of the BUILT-IN roles (design: retire unused defaults).
 *
 * A `tenant_role_availability` row means "this workspace has DISABLED this reserved
 * role"; absent = active, so untouched workspaces keep the four-role default
 * experience. This is a separate policy dimension from capability overrides
 * ({@see TenantRoleOverrideRepository} — "grants X") and from custom-role rows
 * ({@see TenantRoleRepository} — genuine tenant data): overrides for a disabled
 * built-in stay stored and apply again on re-enable. `owner` is never disableable
 * (the anti-lockout role) — enforced in {@see TenantRoleLifecycle}, and reads here
 * treat it as always active for defense in depth.
 *
 * Writes happen inside {@see TenantRoleLifecycle::mutate()}'s transaction (barrier +
 * role lock + policy-version bump + audit) — never standalone — so the
 * {@see EffectiveRoleMatrix} cache invalidates on every availability change for free.
 */
final class BuiltinRoleAvailabilityRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function isDisabled(string $tenantUuid, string $role): bool
    {
        if ($role === 'owner') {
            return false;
        }
        $row = $this->connection->table('tenant_role_availability')
            ->where('tenant_uuid', '=', $tenantUuid)
            ->where('role', '=', $role)
            ->first();

        return is_array($row) && (string) ($row['status'] ?? '') === 'disabled';
    }

    /** @return list<string> */
    public function disabledRoles(string $tenantUuid): array
    {
        $rows = $this->connection->table('tenant_role_availability')
            ->where('tenant_uuid', '=', $tenantUuid)
            ->where('status', '=', 'disabled')
            ->get();

        return array_values(array_filter(array_map(
            static fn (array $row): string => (string) ($row['role'] ?? ''),
            $rows,
        ), static fn (string $role): bool => $role !== '' && $role !== 'owner'));
    }

    /** Caller responsibility: runs inside the lifecycle transaction, guards already applied. */
    public function markDisabled(string $tenantUuid, string $role, ?string $actorUuid): void
    {
        $existing = $this->connection->table('tenant_role_availability')
            ->where('tenant_uuid', '=', $tenantUuid)
            ->where('role', '=', $role)
            ->first();
        if (is_array($existing)) {
            $this->connection->table('tenant_role_availability')
                ->where('tenant_uuid', '=', $tenantUuid)
                ->where('role', '=', $role)
                ->update([
                    'status' => 'disabled',
                    'updated_by' => $actorUuid,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
            return;
        }
        $this->connection->table('tenant_role_availability')->insert([
            'tenant_uuid' => $tenantUuid,
            'role' => $role,
            'status' => 'disabled',
            'updated_by' => $actorUuid,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** Re-enable = remove the row; absent means active. */
    public function markActive(string $tenantUuid, string $role): void
    {
        $this->connection->table('tenant_role_availability')
            ->where('tenant_uuid', '=', $tenantUuid)
            ->where('role', '=', $role)
            ->delete();
    }
}

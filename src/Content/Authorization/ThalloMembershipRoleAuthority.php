<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Authorization;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Tenancy\Membership\MembershipRoleAuthority;

/**
 * THE single source of role assignability: the tenancy engine's membership admin,
 * the member-role picker (`/tenancy/roles/assignable`), and the signup role policy
 * all derive from here, so "which roles can be held in this workspace" can never
 * drift between surfaces.
 *
 * Built-in roles are assignable unless the workspace has disabled them
 * ({@see BuiltinRoleAvailabilityRepository}); `owner` is always assignable (the
 * anti-lockout role, never disableable). Custom roles are assignable while active.
 */
final class ThalloMembershipRoleAuthority implements MembershipRoleAuthority
{
    public function __construct(
        private readonly CapabilityCatalog $catalog,
        private readonly TenantRoleRepository $roles,
        private readonly BuiltinRoleAvailabilityRepository $availability,
    ) {
    }

    public function isAssignable(ApplicationContext $context, string $tenantUuid, string $role): bool
    {
        if (in_array($role, $this->catalog->reservedRoles(), true)) {
            return $role === 'owner' || !$this->availability->isDisabled($tenantUuid, $role);
        }

        return $this->roles->isActive($tenantUuid, $role);
    }

    /**
     * Every role assignable in this workspace, built-ins first (in catalog order),
     * then active custom roles. This is the projection the picker endpoint serves.
     *
     * @return list<array{slug:string,name:string,builtin:bool}>
     */
    public function assignableRoles(string $tenantUuid): array
    {
        $disabled = $this->availability->disabledRoles($tenantUuid);
        $rows = [];
        foreach ($this->catalog->reservedRoles() as $slug) {
            if ($slug !== 'owner' && in_array($slug, $disabled, true)) {
                continue;
            }
            $rows[] = ['slug' => $slug, 'name' => ucfirst($slug), 'builtin' => true];
        }
        foreach ($this->roles->all($tenantUuid, true) as $role) {
            $rows[] = ['slug' => $role['slug'], 'name' => $role['name'], 'builtin' => false];
        }

        return $rows;
    }
}

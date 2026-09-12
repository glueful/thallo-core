<?php

declare(strict_types=1);

namespace Thallo\Core\Signup;

use Thallo\Core\Content\Authorization\CapabilityCatalog;
use Thallo\Core\Content\Authorization\EffectiveRoleMatrix;
use Thallo\Core\Content\Authorization\ThalloMembershipRoleAuthority;
use Glueful\Bootstrap\ApplicationContext;

final class SignupRolePolicy
{
    private const FORBIDDEN = [
        'tenant.roles.manage',
        'tenant.members.manage',
        'tenant.domains.manage',
    ];

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly CapabilityCatalog $catalog,
        private readonly EffectiveRoleMatrix $effective,
        private readonly ThalloMembershipRoleAuthority $authority,
    ) {
    }

    public function isEligible(string $tenantUuid, string $roleSlug): bool
    {
        $roleSlug = trim($roleSlug);
        if ($roleSlug === '' || $roleSlug === 'owner') {
            return false;
        }
        if (!$this->authority->isAssignable($this->context, $tenantUuid, $roleSlug)) {
            return false;
        }

        return array_intersect(
            $this->effective->capabilitiesFor($tenantUuid, $roleSlug),
            self::FORBIDDEN,
        ) === [];
    }

    /** @return list<string> */
    public function eligibleRoles(string $tenantUuid): array
    {
        $roles = $this->catalog->reservedRoles();
        $connection = db($this->context);
        if ($connection->getSchemaBuilder()->hasTable('tenant_roles')) {
            foreach (
                $connection->table('tenant_roles')
                    ->where('tenant_uuid', '=', $tenantUuid)
                    ->where('status', '=', 'active')
                    ->orderBy('name', 'asc')
                    ->get() as $row
            ) {
                $roles[] = (string) ($row['slug'] ?? '');
            }
        }

        $roles = array_values(array_unique(array_filter(
            $roles,
            fn (string $role): bool => $this->isEligible($tenantUuid, $role),
        )));
        sort($roles);
        return $roles;
    }
}

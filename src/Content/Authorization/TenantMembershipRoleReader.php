<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Authorization;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Symfony\Component\HttpFoundation\Request;

/** Indexed membership-role lookup with cache state confined to one Symfony request. */
final class TenantMembershipRoleReader
{
    private const ATTRIBUTE = '_thallo_tenant_membership_roles';

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ?CurrentTenantResolver $resolver = null,
    ) {
    }

    public function resolvedTenantUuid(): ?string
    {
        if ($this->resolver === null) {
            return null;
        }
        $uuid = trim($this->resolver->tenantUuid($this->context));
        return $uuid === '' ? null : $uuid;
    }

    public function roleFor(Request $request, string $userUuid): ?string
    {
        $tenantUuid = $this->resolvedTenantUuid();
        if ($tenantUuid === null || trim($userUuid) === '') {
            return null;
        }

        $key = $tenantUuid . ':' . $userUuid;
        $memo = $request->attributes->get(self::ATTRIBUTE, []);
        $memo = is_array($memo) ? $memo : [];
        if (array_key_exists($key, $memo)) {
            return is_string($memo[$key]) ? $memo[$key] : null;
        }

        $row = db($this->context)->table('tenant_memberships')
            ->where('tenant_uuid', '=', $tenantUuid)
            ->where('user_uuid', '=', $userUuid)
            ->where('status', '=', 'active')
            ->first();
        $role = is_array($row) && is_string($row['role'] ?? null) ? $row['role'] : null;
        $memo[$key] = $role;
        $request->attributes->set(self::ATTRIBUTE, $memo);

        return $role;
    }
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Http\Controllers;

use Thallo\Core\Content\Authorization\AuthenticatedPrincipalResolver;
use Thallo\Core\Content\Authorization\OperatorBypass;
use Thallo\Core\Content\Authorization\PermissionAuthority;
use Thallo\Core\Content\Authorization\EffectiveRoleMatrix;
use Thallo\Core\Content\Authorization\TenantMembershipRoleReader;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

final class TenancyAccessController
{
    public function __construct(
        private readonly AuthenticatedPrincipalResolver $principals,
        private readonly PermissionAuthority $permissions,
        private readonly ?EffectiveRoleMatrix $matrix = null,
        private readonly ?TenantMembershipRoleReader $roleReader = null,
        private readonly ?OperatorBypass $bypass = null,
    ) {
    }

    public function access(Request $request): Response
    {
        $principal = $this->principals->resolve($request);
        if ($principal === null || $this->matrix === null || $this->roleReader === null || $this->bypass === null) {
            return Response::success(['access' => $this->denyAll()]);
        }

        $uuid = $principal['uuid'];
        $context = $this->principals->aegisContext($request, $principal);

        return Response::success(['access' => [
            'manage_platform' => $this->permissions->can($uuid, 'tenancy.manage', 'thallo', $context),
            'access_any' => $this->permissions->can($uuid, 'tenancy.access_any', 'thallo', $context),
            'manage_members' => $this->effective($request, $uuid, 'tenant.members.manage', $context),
            'manage_domains' => $this->effective($request, $uuid, 'tenant.domains.manage', $context),
            'manage_roles' => $this->effective($request, $uuid, 'tenant.roles.manage', $context),
            'manage_billing' => $this->effective($request, $uuid, 'billing.manage', $context),
        ]]);
    }

    /** @param array<string,mixed> $context */
    private function effective(Request $request, string $uuid, string $capability, array $context): bool
    {
        $tenantUuid = $this->roleReader?->resolvedTenantUuid();
        if ($tenantUuid === null || $this->matrix === null || $this->bypass === null) {
            return false;
        }

        $role = $this->roleReader->roleFor($request, $uuid);
        return ($role !== null && $this->matrix->allows($tenantUuid, $role, $capability))
            || $this->bypass->decide(
                $request,
                $uuid,
                $role,
                $capability,
                $tenantUuid,
                $context,
            )->granted;
    }

    /**
     * @return array{
     *     manage_platform:false,access_any:false,manage_members:false,manage_domains:false,
     *     manage_roles:false,manage_billing:false,
     * }
     */
    private function denyAll(): array
    {
        return [
            'manage_platform' => false,
            'access_any' => false,
            'manage_members' => false,
            'manage_domains' => false,
            'manage_roles' => false,
            'manage_billing' => false,
        ];
    }
}

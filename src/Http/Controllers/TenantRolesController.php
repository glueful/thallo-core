<?php

declare(strict_types=1);

namespace Thallo\Core\Http\Controllers;

use Thallo\Core\Content\Authorization\AuthenticatedPrincipalResolver;
use Thallo\Core\Content\Authorization\BuiltinRoleAvailabilityRepository;
use Thallo\Core\Content\Authorization\CapabilityCatalog;
use Thallo\Core\Content\Authorization\EffectiveRoleMatrix;
use Thallo\Core\Content\Authorization\PermissionAuthority;
use Thallo\Core\Content\Authorization\RoleMatrix;
use Thallo\Core\Content\Authorization\RoleOverrideException;
use Thallo\Core\Content\Authorization\TenantMembershipRoleReader;
use Thallo\Core\Content\Authorization\TenantRoleOverrideRepository;
use Thallo\Core\Content\Authorization\TenantRolePolicyMutator;
use Thallo\Core\Content\Authorization\TenantRoleRepository;
use Thallo\Core\Content\Authorization\TenantRoleLifecycle;
use Thallo\Core\Content\Authorization\TenantRoleLifecycleException;
use Thallo\Core\Content\Authorization\ThalloMembershipRoleAuthority;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Tenancy\System\SystemFlags;
use Thallo\Tenancy\Tenant\SingleStoreTenant;

final class TenantRolesController
{
    public function __construct(
        private readonly AuthenticatedPrincipalResolver $principals,
        private readonly TenantMembershipRoleReader $membership,
        private readonly CapabilityCatalog $catalog,
        private readonly RoleMatrix $baseline,
        private readonly EffectiveRoleMatrix $effective,
        private readonly TenantRoleOverrideRepository $overrides,
        private readonly TenantRolePolicyMutator $mutator,
        private readonly TenantRoleRepository $roles,
        private readonly TenantRoleLifecycle $lifecycle,
        private readonly PermissionAuthority $permissions,
        private readonly SystemFlags $flags,
        private readonly SingleStoreTenant $singleStore,
        private readonly BuiltinRoleAvailabilityRepository $availability,
        private readonly ThalloMembershipRoleAuthority $authority,
    ) {
    }

    public function index(Request $request): Response
    {
        $tenantUuid = $this->tenantUuid($request);
        if ($tenantUuid === null) {
            return Response::error('Workspace context is required.', Response::HTTP_FORBIDDEN);
        }
        $deltas = $this->overrides->overridesFor($tenantUuid);
        $matrix = $this->baseline->capabilities();
        $rows = [];
        foreach ($this->catalog->reservedRoles() as $role) {
            $grants = [];
            $revokes = [];
            foreach ($deltas[$role] ?? [] as $capability => $effect) {
                if ($effect === 'revoke') {
                    $revokes[] = $capability;
                } else {
                    $grants[] = $capability;
                }
            }
            $rows[] = [
                'slug' => $role,
                'name' => ucfirst($role),
                'builtin' => true,
                // Per-workspace availability: a disabled built-in stays listed (collapsed
                // under "Inactive roles" in the UI, discoverable + re-enableable) but is
                // withheld from every assignment surface via the role authority.
                'status' => $this->availability->isDisabled($tenantUuid, $role) ? 'disabled' : 'active',
                'baseline' => $matrix[$role] ?? [],
                'grants' => $grants,
                'revokes' => $revokes,
                'effective' => $this->effective->capabilitiesFor($tenantUuid, $role),
                'drift' => array_values(array_filter(
                    array_keys($deltas[$role] ?? []),
                    fn (string $capability): bool => !$this->catalog->has($capability),
                )),
            ];
        }
        foreach ($this->roles->all($tenantUuid) as $role) {
            $grants = array_keys(array_filter(
                $deltas[$role['slug']] ?? [],
                static fn (string $effect): bool => $effect === 'grant',
            ));
            $rows[] = [
                'slug' => $role['slug'],
                'name' => $role['name'],
                'builtin' => false,
                'status' => $role['status'],
                'baseline' => [],
                'grants' => $grants,
                'revokes' => [],
                'effective' => $this->effective->capabilitiesFor($tenantUuid, $role['slug']),
                'drift' => array_values(array_filter(
                    $grants,
                    fn (string $capability): bool => !$this->catalog->has($capability),
                )),
            ];
        }
        return Response::success(['roles' => $rows, 'catalog' => $this->catalog->all()]);
    }

    public function create(Request $request): Response
    {
        [$tenantUuid, $actorUuid] = $this->tenantActor($request);
        if ($tenantUuid === null || $actorUuid === null) {
            return Response::error('Workspace context is required.', Response::HTTP_FORBIDDEN);
        }
        $body = $this->body($request);
        try {
            $this->lifecycle->create(
                $tenantUuid,
                is_string($body['slug'] ?? null) ? $body['slug'] : '',
                is_string($body['name'] ?? null) ? $body['name'] : '',
                $actorUuid,
            );
            return Response::created(['role' => $this->roles->find($tenantUuid, (string) $body['slug'])]);
        } catch (TenantRoleLifecycleException $exception) {
            return Response::validation($exception->errors ?: ['role' => $exception->getMessage()]);
        }
    }

    public function update(Request $request, string $slug): Response
    {
        [$tenantUuid, $actorUuid] = $this->tenantActor($request);
        if ($tenantUuid === null || $actorUuid === null) {
            return Response::error('Workspace context is required.', Response::HTTP_FORBIDDEN);
        }
        $body = $this->body($request);
        $hasName = is_string($body['name'] ?? null);
        $status = is_string($body['status'] ?? null) ? $body['status'] : null;
        if (($hasName ? 1 : 0) + ($status !== null ? 1 : 0) !== 1) {
            return Response::validation(['role' => 'Change exactly one of name or status.']);
        }
        // Built-in roles: only availability can change — their names and capability
        // baselines are code-defined vocabulary (config/tenancy.php), never rows.
        if (in_array($slug, $this->catalog->reservedRoles(), true)) {
            if ($hasName) {
                return Response::validation(['name' => 'Built-in roles cannot be renamed.']);
            }
            try {
                if ($status === 'disabled') {
                    $reassign = $body['reassign_to'] ?? null;
                    $signupRole = $body['signup_role'] ?? null;
                    $this->lifecycle->disableBuiltin(
                        $tenantUuid,
                        $slug,
                        is_string($reassign) ? $reassign : null,
                        is_string($signupRole) ? $signupRole : null,
                        $actorUuid,
                    );
                } elseif ($status === 'active') {
                    $this->lifecycle->enableBuiltin($tenantUuid, $slug, $actorUuid);
                } else {
                    return Response::validation(['status' => 'Status must be active or disabled.']);
                }
                return Response::success(['role' => [
                    'slug' => $slug,
                    'name' => ucfirst($slug),
                    'builtin' => true,
                    'status' => $this->availability->isDisabled($tenantUuid, $slug) ? 'disabled' : 'active',
                ]]);
            } catch (TenantRoleLifecycleException $exception) {
                return Response::validation($exception->errors ?: ['role' => $exception->getMessage()]);
            }
        }
        try {
            if ($hasName) {
                $this->lifecycle->rename($tenantUuid, $slug, (string) $body['name'], $actorUuid);
            } elseif ($status === 'active') {
                $this->lifecycle->enable($tenantUuid, $slug, $actorUuid);
            } elseif ($status === 'disabled') {
                $this->lifecycle->disable($tenantUuid, $slug, $actorUuid);
            } else {
                return Response::validation(['status' => 'Status must be active or disabled.']);
            }
            return Response::success(['role' => $this->roles->find($tenantUuid, $slug)]);
        } catch (TenantRoleLifecycleException $exception) {
            return Response::validation($exception->errors ?: ['role' => $exception->getMessage()]);
        }
    }

    public function delete(Request $request, string $slug): Response
    {
        [$tenantUuid, $actorUuid] = $this->tenantActor($request);
        if ($tenantUuid === null || $actorUuid === null) {
            return Response::error('Workspace context is required.', Response::HTTP_FORBIDDEN);
        }
        $reassign = $request->query->get('reassign_to');
        try {
            $this->lifecycle->delete(
                $tenantUuid,
                $slug,
                is_string($reassign) && trim($reassign) !== '' ? trim($reassign) : null,
                $actorUuid,
            );
            return Response::success([], 'Workspace role deleted.');
        } catch (TenantRoleLifecycleException $exception) {
            return Response::validation($exception->errors ?: ['role' => $exception->getMessage()]);
        }
    }

    public function assignable(Request $request): Response
    {
        $tenantUuid = $this->tenantUuid($request);
        if ($tenantUuid === null) {
            return Response::error('Workspace context is required.', Response::HTTP_FORBIDDEN);
        }
        // Single source: the same MembershipRoleAuthority the tenancy engine and the
        // signup role policy consult — picker, assignment, and signup can never drift.
        return Response::success([
            'roles' => $this->authority->assignableRoles($tenantUuid),
        ]);
    }

    public function overrides(Request $request, string $slug): Response
    {
        $tenantUuid = $this->tenantUuid($request);
        $principal = $this->principals->resolve($request);
        if ($tenantUuid === null || $principal === null) {
            return Response::error('Workspace context is required.', Response::HTTP_FORBIDDEN);
        }
        $body = $this->body($request);
        try {
            $result = $this->mutator->reconcile(
                $tenantUuid,
                $slug,
                $this->stringList($body['grants'] ?? []),
                $this->stringList($body['revokes'] ?? []),
                $principal['uuid'],
            );
            return Response::success(['policy' => $result], 'Workspace role overrides updated.');
        } catch (RoleOverrideException $exception) {
            return Response::validation($exception->errors ?: ['role' => $exception->getMessage()]);
        }
    }

    public function preview(Request $request): Response
    {
        $tenantUuid = $this->tenantUuid($request);
        if ($tenantUuid === null) {
            return Response::error('Workspace context is required.', Response::HTTP_FORBIDDEN);
        }
        $body = $this->body($request);
        $role = is_string($body['role_slug'] ?? null) ? trim($body['role_slug']) : '';
        $grants = $this->stringList($body['grants'] ?? []);
        $revokes = $this->stringList($body['revokes'] ?? []);
        try {
            [$grants, $revokes] = $this->overrides->validateDesiredSet(
                $tenantUuid,
                $role,
                $grants,
                $revokes,
            );
        } catch (RoleOverrideException $exception) {
            return Response::validation($exception->errors ?: ['role' => $exception->getMessage()]);
        }
        $before = $this->effective->capabilitiesFor($tenantUuid, $role);
        $set = array_fill_keys($this->baseline->capabilities()[$role] ?? [], true);
        foreach ($grants as $capability) {
            $set[$capability] = true;
        }
        foreach ($revokes as $capability) {
            unset($set[$capability]);
        }
        if ($role === 'owner') {
            foreach ($this->catalog->ownerFloor() as $capability) {
                $set[$capability] = true;
            }
        }
        $after = array_keys($set);
        sort($after);
        return Response::success(['preview' => [
            'before' => $before,
            'after' => $after,
            'added' => array_values(array_diff($after, $before)),
            'removed' => array_values(array_diff($before, $after)),
        ]]);
    }

    public function reset(Request $request, string $tenant): Response
    {
        $principal = $this->principals->resolve($request);
        if ($principal === null || $request->headers->get('X-Tenant-Operator-Mode') !== '1') {
            return Response::error('Forbidden', Response::HTTP_FORBIDDEN);
        }
        $context = $this->principals->aegisContext($request, $principal);
        if (
            !$this->permissions->can($principal['uuid'], 'tenancy.manage', 'thallo', $context)
            || !$this->permissions->can($principal['uuid'], 'tenancy.access_any', 'thallo', $context)
        ) {
            return Response::error('Forbidden', Response::HTTP_FORBIDDEN);
        }
        return Response::success([
            'policy' => $this->mutator->reset($tenant, $principal['uuid']),
        ], 'Workspace role overrides reset.');
    }

    /** @return array<string,mixed> */
    private function body(Request $request): array
    {
        $body = json_decode((string) $request->getContent(), true);
        return is_array($body) ? $body : [];
    }

    /** @return list<string> */
    private function stringList(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /** @return array{0:?string,1:?string} */
    private function tenantActor(Request $request): array
    {
        return [$this->tenantUuid($request), $this->principals->resolve($request)['uuid'] ?? null];
    }

    private function tenantUuid(Request $request): ?string
    {
        if (str_starts_with($request->getPathInfo(), '/v1/admin/settings/signup/roles')) {
            return $this->flags->tenancyEnabled() ? null : $this->singleStore->defaultUuidOrNull();
        }

        return $this->membership->resolvedTenantUuid();
    }
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Http\Middleware;

use Thallo\Core\Content\Authorization\AuthenticatedPrincipalResolver;
use Thallo\Core\Content\Authorization\PermissionAuthority;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\FullTenantResolutionReadiness;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Http\Response;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;

/**
 * Binds an admin-console request to the workspace the operator selected (the `X-Tenant-Id`
 * header from the tenant switcher), so every tenant-owned read/write in the request is scoped
 * to exactly one workspace.
 *
 * This closes a gap that only appears once full resolution is armed: in bootstrap mode
 * `tenant_bootstrap` binds the single default tenant, but under full resolution the admin
 * console runs on one host and picks its workspace by header — host resolution never binds it,
 * so without this middleware tenant-owned reads (content types, block types, media, …) leak
 * across every workspace. It stays inert until full resolution is ready.
 *
 * Authorization: members bind their own workspace directly; a non-member may only bind a
 * workspace they can reach with cross-tenant operator authority (`tenancy.access_any`), and
 * only when that workspace is active. Per-route `content_permission:*` checks remain the
 * enforcement inside the bound context — this middleware is the defense-in-depth gate that
 * keeps routes lacking such a check from leaking, and picks the workspace to bind.
 */
final class AdminTenantBindingMiddleware implements RouteMiddleware
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly AuthenticatedPrincipalResolver $principals,
        private readonly PermissionAuthority $permissions,
        private readonly ?TenantAdministration $tenants = null,
        private readonly ?TenantContextRunner $runner = null,
        private readonly ?FullTenantResolutionReadiness $readiness = null,
    ) {
    }

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        // Inert until full resolution is armed; before that, `tenant_bootstrap` binds the default.
        if ($this->readiness === null || !$this->readiness->isReady($this->context)) {
            return $next($request);
        }
        if ($this->runner === null || $this->tenants === null) {
            return Response::error('Tenant resolution is unavailable.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $principal = $this->principals->resolve($request);
        if ($principal === null) {
            return Response::forbidden('Authentication is required to manage a workspace.');
        }
        $userUuid = $principal['uuid'];

        $memberUuids = array_values(array_filter(
            array_map(
                static fn (array $t): string => (string) ($t['uuid'] ?? ''),
                $this->tenants->listTenantsForUser($this->context, $userUuid),
            ),
            static fn (string $uuid): bool => $uuid !== '',
        ));

        $header = trim((string) $request->headers->get('X-Tenant-Id', ''));
        $canAccessAny = $this->permissions->can(
            $userUuid,
            'tenancy.access_any',
            'thallo',
            $this->principals->aegisContext($request, $principal),
        );

        $selected = $this->selectTenant($header, $memberUuids, $canAccessAny);
        if ($selected instanceof Response) {
            return $selected;
        }

        return $this->runner->runAsTenant($selected, static fn (): mixed => $next($request));
    }

    /**
     * Decide which workspace to bind, or return a 403 when the principal may not manage the
     * selected (or any) workspace.
     *
     * @param list<string> $memberUuids the principal's active workspace memberships
     * @return string|Response the workspace uuid to bind, or a forbidden response
     */
    public function selectTenant(string $header, array $memberUuids, bool $canAccessAny): string|Response
    {
        if ($header !== '') {
            if (in_array($header, $memberUuids, true)) {
                return $header;
            }
            if (!$canAccessAny) {
                return Response::forbidden('You do not have access to the selected workspace.');
            }
            $tenant = $this->tenants?->getTenant($this->context, $header);
            if ($tenant === null || ($tenant['status'] ?? '') !== 'active') {
                return Response::forbidden('The selected workspace is unavailable.');
            }

            return $header;
        }

        // No explicit selection: bind the principal's first workspace so the console is never
        // left unscoped. A pure operator with no membership must pick a workspace explicitly.
        if ($memberUuids !== []) {
            return $memberUuids[0];
        }

        return Response::forbidden('Select a workspace to manage.');
    }
}

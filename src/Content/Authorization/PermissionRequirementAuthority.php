<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Authorization;

use Glueful\Auth\ApiKey\ApiKeyService;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Permissions\PermissionManager;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Authorization\PermissionRequirementAuthority as PermissionRequirementAuthorityContract;

/**
 * The single authorization authority for permission REQUIREMENTS (spec §4.2).
 *
 * A route (or an effective-flags endpoint like /meta) states a list of required
 * permission alternatives; the request passes when ANY required candidate `P` is
 * satisfied. Implications are declarative data from a {@see PermissionImplicationSource}
 * (identity when none is bound), expanded to `satisfiers(P)` before evaluation:
 *
 *     JWT:     allowed = ∃ required P where any live RBAC grant in satisfiers(P) allows
 *     API key: allowed = ∃ required P where
 *                a live RBAC grant in satisfiers(P) allows
 *                AND a key scope satisfies some grant in satisfiers(P)
 *
 * Both factors must satisfy the SAME required `P` (candidate-wise intersection) — a key
 * scope matching one unrelated alternative while RBAC matches another never authorizes.
 * Empty requirements and empty key-scope lists deny; wildcard scope matching keeps the
 * framework's fnmatch semantics. All of RequirePermission's fail-closed branches and the
 * tenant-mode matrix/bypass evaluation are preserved here unchanged.
 *
 * Implements the neutral {@see PermissionRequirementAuthorityContract} (Task 8,
 * admin-commerce-area plan slice 3) so a first-party pack (e.g. thallo-commerce's `/meta`
 * endpoint) can depend on the SAME effective-permission decision without referencing this
 * `Thallo\Core\` namespace directly — packs may not depend on the engine app. `ThalloServiceProvider`
 * binds the contract to THIS shared instance (see `makePermissionRequirementAuthority()`), so
 * the contract and the `content_permission` route middleware can never disagree.
 */
final class PermissionRequirementAuthority implements PermissionRequirementAuthorityContract
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ?PermissionImplicationSource $implications = null,
        private readonly ?TenantMembershipRoleReader $roleReader = null,
        private readonly ?EffectiveRoleMatrix $matrix = null,
        private readonly ?OperatorBypass $bypass = null,
        private readonly ?AuthenticatedPrincipalResolver $principals = null,
        private readonly ?PermissionAuthority $permissions = null,
    ) {
    }

    /**
     * @param list<string> $requirements required permission alternatives (any-of)
     */
    public function allows(Request $request, array $requirements): bool
    {
        if ($requirements === []) {
            return false;
        }

        // API-key scope facts, resolved once. Empty scope list is a deny, NOT the
        // framework's legacy "full access" — the key OWNER's RBAC alone never
        // authorizes a key (a leaked narrow key must not inherit admin rights).
        $isApiKey = $request->attributes->get('auth_method') === 'api_key';
        $scopes = [];
        if ($isApiKey) {
            $scopes = array_values(array_filter(
                (array) $request->attributes->get('api_key_scopes', []),
                'is_string',
            ));
            if ($scopes === []) {
                return false;
            }
        }

        $principals = $this->principals ?? new AuthenticatedPrincipalResolver();
        $principal = $principals->resolve($request);
        if ($principal === null) {
            return false;
        }

        $tenantContextPresent = $this->context->getRequestState('tenancy.tenant') !== null;
        if (!$tenantContextPresent) {
            $permissions = $this->permissions ?? new PermissionAuthority($this->context);
            if (!$permissions->manager() instanceof PermissionManager) {
                return false;
            }
        } elseif ($this->roleReader === null || $this->matrix === null || $this->bypass === null) {
            return false;
        }

        $aegisContext = $principals->aegisContext($request, $principal);

        foreach ($requirements as $required) {
            $satisfiers = $this->satisfiersFor($required);

            if ($isApiKey && !$this->scopeSatisfiesAny($scopes, $satisfiers)) {
                continue; // the key cannot satisfy this candidate — try the next one
            }

            if ($this->rbacAllowsAny($request, $principal, $satisfiers, $aegisContext, $tenantContextPresent)) {
                return true;
            }
        }

        return false;
    }

    /** @return non-empty-list<string> */
    private function satisfiersFor(string $required): array
    {
        if ($this->implications === null) {
            return [$required];
        }
        $satisfiers = $this->implications->satisfiersFor($required);

        return $satisfiers === [] ? [$required] : $satisfiers;
    }

    /**
     * @param list<string> $scopes
     * @param non-empty-list<string> $satisfiers
     */
    private function scopeSatisfiesAny(array $scopes, array $satisfiers): bool
    {
        foreach ($satisfiers as $grant) {
            if (ApiKeyService::scopeSatisfies($scopes, $grant)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $principal
     * @param non-empty-list<string> $satisfiers
     * @param array<string, mixed> $aegisContext
     */
    private function rbacAllowsAny(
        Request $request,
        array $principal,
        array $satisfiers,
        array $aegisContext,
        bool $tenantContextPresent,
    ): bool {
        if (!$tenantContextPresent) {
            $permissions = $this->permissions ?? new PermissionAuthority($this->context);
            $resource = $this->resourceFor($request);
            foreach ($satisfiers as $grant) {
                if ($permissions->can((string) $principal['uuid'], $grant, $resource, $aegisContext)) {
                    return true;
                }
            }

            return false;
        }

        // Tenant mode: nullability already guarded in allows().
        $resolvedTenant = $this->roleReader?->resolvedTenantUuid();
        if ($resolvedTenant === null) {
            return false;
        }
        $role = $this->roleReader?->roleFor($request, (string) $principal['uuid']);
        foreach ($satisfiers as $grant) {
            $allowed = ($role !== null && $this->matrix !== null
                    && $this->matrix->allows($resolvedTenant, $role, $grant))
                || ($this->bypass !== null && $this->bypass->evaluate(
                    $request,
                    (string) $principal['uuid'],
                    $role,
                    $grant,
                    $resolvedTenant,
                    $aegisContext,
                )->granted);
            if ($allowed) {
                return true;
            }
        }

        return false;
    }

    /**
     * Locale-specific routes carry `{locale}` in `_route_params`; those actions are
     * scoped to `locale:<code>`, all others to the coarse `thallo` resource.
     */
    private function resourceFor(Request $request): string
    {
        $params = (array) $request->attributes->get('_route_params');
        $locale = $params['locale'] ?? null;

        return is_string($locale) && $locale !== '' ? "locale:{$locale}" : 'thallo';
    }
}

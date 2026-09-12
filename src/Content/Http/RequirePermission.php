<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http;

use Thallo\Core\Content\Authorization\PermissionRequirementAuthority;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Response;
use Glueful\Routing\RouteMiddleware;
use Symfony\Component\HttpFoundation\Request;

/**
 * Requires the authenticated user to satisfy ANY of the listed Thallo RBAC permission
 * alternatives (spec §4.2 any-of candidates).
 *
 * Registered under the `content_permission` alias. The router comma-splits
 * `content_permission:a,b` into multiple middleware params — each is one required
 * alternative. This middleware is a thin HTTP adapter: parameter parsing here, all
 * evaluation (implication expansion, candidate-wise API-key scope∩RBAC intersection,
 * tenant matrix/bypass, resource derivation) in {@see PermissionRequirementAuthority} —
 * the same authority effective-flag endpoints consume, so no logic is duplicated.
 *
 * Fails closed: no valid requirement params, no authenticated identity, an unresolvable
 * PermissionManager, or a denied evaluation all return 403.
 */
final class RequirePermission implements RouteMiddleware
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ?PermissionRequirementAuthority $authority = null,
    ) {
    }

    public function handle(Request $request, callable $next, mixed ...$params): mixed
    {
        // Type-safe parsing: ignore non-strings entirely, trim only strings, and
        // discard empties. An empty resulting list fails closed.
        $requirements = [];
        foreach ($params as $param) {
            if (!is_string($param)) {
                continue;
            }
            $trimmed = trim($param);
            if ($trimmed !== '') {
                $requirements[] = $trimmed;
            }
        }

        $authority = $this->authority ?? new PermissionRequirementAuthority($this->context);
        if ($requirements === [] || !$authority->allows($request, $requirements)) {
            return Response::error('Forbidden', Response::HTTP_FORBIDDEN, ['code' => 'FORBIDDEN']);
        }

        return $next($request);
    }
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Http\Controllers;

use Thallo\Core\Support\ActorHelper;
use Thallo\Core\Support\RoleAuthority;
use Thallo\Core\Support\UserRoleAssignmentPolicy;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Extensions\Aegis\Models\Role;
use Glueful\Extensions\Aegis\Repositories\RoleRepository;
use Glueful\Extensions\Users\Repositories\UserRepository;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/** Server-derived role picker that includes protected assigned roles only as locked rows. */
final class AssignableRolesController
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly AegisPermissionProvider $aegis,
        private readonly UserRoleAssignmentPolicy $policy,
        private readonly UserRepository $users,
    ) {
    }

    #[ApiOperation(summary: 'List assignable roles', tags: ['Users'])]
    #[ApiResponse(200, description: 'Roles the caller may assign and protected target roles.')]
    public function index(Request $request): Response
    {
        $actor = ActorHelper::uuidFromRequest($request) ?? '';
        $targetUuid = trim((string) $request->query->get('target_uuid', ''));
        if ($targetUuid !== '' && $this->users->findByUuid($targetUuid) === null) {
            return Response::notFound('User not found.');
        }

        $assigned = [];
        if ($targetUuid !== '') {
            foreach ($this->aegis->getUserRoles($targetUuid) as $role) {
                if ($role instanceof Role) {
                    $assigned[$role->getSlug()] = true;
                }
            }
        }

        $rows = [];
        foreach ((new RoleRepository(null, $this->context))->findAllRoles() as $role) {
            if (!$role instanceof Role || !$role->isActive()) {
                continue;
            }
            $slug = $role->getSlug();
            $isAssigned = isset($assigned[$slug]);
            if ($slug === RoleAuthority::SUPERUSER) {
                if ($targetUuid !== '' && $isAssigned) {
                    $rows[] = $this->row($role, true, false, false);
                }
                continue;
            }

            $canAdd = $this->policy->mayAdd($actor, $role);
            $canRemove = $this->policy->mayRemove($actor, $role);
            if ($targetUuid === '') {
                if ($canAdd) {
                    $rows[] = $this->row($role, false, true, false);
                }
                continue;
            }
            if ($canAdd || $isAssigned) {
                $rows[] = $this->row($role, $isAssigned, $canAdd, $isAssigned && $canRemove);
            }
        }

        return Response::success(['roles' => $rows], 'Assignable roles retrieved.');
    }

    /** @return array{slug:string,name:string,assigned:bool,assignable:bool,removable:bool} */
    private function row(Role $role, bool $assigned, bool $assignable, bool $removable): array
    {
        return [
            'slug' => $role->getSlug(),
            'name' => $role->getName(),
            'assigned' => $assigned,
            'assignable' => $assignable,
            'removable' => $removable,
        ];
    }
}

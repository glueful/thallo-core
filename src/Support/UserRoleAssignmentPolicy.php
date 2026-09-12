<?php

declare(strict_types=1);

namespace Thallo\Core\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Extensions\Aegis\Models\Role;
use Glueful\Extensions\Aegis\Repositories\RoleRepository;

/** Escalation policy for global Aegis role assignment through Thallo's user API. */
final class UserRoleAssignmentPolicy
{
    private const MANAGE_PERMISSION = 'users.roles.manage';

    private ?RoleRepository $roles = null;

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly AegisPermissionProvider $aegis,
        private readonly RoleAuthority $authority,
        private readonly AuthorityAudit $audit,
    ) {
    }

    /** @param list<string> $currentSlugs @param list<string> $desiredSlugs */
    public function assertCanSyncRoles(
        string $actorUuid,
        string $targetUuid,
        array $currentSlugs,
        array $desiredSlugs,
    ): void {
        $added = array_values(array_diff($desiredSlugs, $currentSlugs));
        $removed = array_values(array_diff($currentSlugs, $desiredSlugs));
        if ($added === [] && $removed === []) {
            return;
        }

        if (!$this->canManageRoles($actorUuid)) {
            $this->deny(
                $actorUuid,
                $targetUuid,
                'no_manage_permission',
                'You do not have permission to manage user roles.',
            );
        }
        if (!$this->authority->isCanonicalSuperuser($actorUuid) && $actorUuid === $targetUuid) {
            $this->deny($actorUuid, $targetUuid, 'self_change', 'You cannot change your own roles.');
        }

        foreach ($added as $slug) {
            $role = $this->requireRole($slug, $actorUuid, $targetUuid);
            if (!$this->mayAdd($actorUuid, $role)) {
                $this->deny($actorUuid, $targetUuid, 'add_denied', "You cannot assign the role '{$slug}'.");
            }
        }
        foreach ($removed as $slug) {
            $role = $this->requireRole($slug, $actorUuid, $targetUuid);
            if (!$this->mayRemove($actorUuid, $role)) {
                $this->deny($actorUuid, $targetUuid, 'remove_denied', "You cannot revoke the role '{$slug}'.");
            }
        }
    }

    public function mayAdd(string $actorUuid, Role $role): bool
    {
        $slug = $role->getSlug();
        if ($slug === RoleAuthority::SUPERUSER || !$role->isActive()) {
            return false;
        }
        if ($this->maxLevel($actorUuid) <= $role->getLevel()) {
            return false;
        }
        $superuser = $this->authority->isCanonicalSuperuser($actorUuid);
        if ($slug === RoleAuthority::WORKSPACE_MANAGER && !$superuser) {
            return false;
        }
        return $superuser || $this->authority->actorHoldsAllPermissionsOf($actorUuid, $slug);
    }

    public function mayRemove(string $actorUuid, Role $role): bool
    {
        $slug = $role->getSlug();
        if ($slug === RoleAuthority::SUPERUSER || $this->maxLevel($actorUuid) <= $role->getLevel()) {
            return false;
        }
        return $slug !== RoleAuthority::WORKSPACE_MANAGER
            || $this->authority->isCanonicalSuperuser($actorUuid);
    }

    private function requireRole(string $slug, string $actorUuid, string $targetUuid): Role
    {
        $role = $this->roles()->findRoleBySlug($slug);
        if ($role instanceof Role) {
            return $role;
        }
        $this->audit->record('security.role_assignment_denied', $actorUuid, $targetUuid, [
            'role' => $slug,
            'outcome' => 'denied',
            'reason' => 'unknown_role',
        ]);
        throw RoleAssignmentException::unprocessable("Unknown role '{$slug}'.");
    }

    private function deny(string $actorUuid, string $targetUuid, string $reason, string $message): never
    {
        $this->audit->record('security.role_assignment_denied', $actorUuid, $targetUuid, [
            'outcome' => 'denied',
            'reason' => $reason,
        ]);
        throw RoleAssignmentException::forbidden($message);
    }

    private function canManageRoles(string $actorUuid): bool
    {
        try {
            return $this->aegis->can($actorUuid, self::MANAGE_PERMISSION, 'thallo');
        } catch (\Throwable) {
            return false;
        }
    }

    private function maxLevel(string $actorUuid): int
    {
        $max = 0;
        foreach ($this->aegis->getUserRoles($actorUuid) as $role) {
            if ($role instanceof Role && $role->isActive()) {
                $max = max($max, $role->getLevel());
            }
        }
        return $max;
    }

    private function roles(): RoleRepository
    {
        return $this->roles ??= new RoleRepository(null, $this->context);
    }
}

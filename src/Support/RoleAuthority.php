<?php

declare(strict_types=1);

namespace Thallo\Core\Support;

use Glueful\Database\Connection;
use Glueful\Extensions\Aegis\AegisPermissionProvider;

/** Read-only authority facts derived from active Aegis assignments and effective permissions. */
final class RoleAuthority
{
    public const SUPERUSER = 'superuser';
    public const WORKSPACE_MANAGER = 'workspace_manager';
    public const ACCESS_ANY = 'tenancy.access_any';

    public function __construct(
        private readonly Connection $db,
        private readonly AegisPermissionProvider $aegis,
    ) {
    }

    public function isCanonicalSuperuser(string $userUuid): bool
    {
        $stmt = $this->db->getPDO()->prepare(
            "SELECT 1 FROM user_roles ur
             JOIN roles r ON r.uuid = ur.role_uuid
             JOIN users u ON u.uuid = ur.user_uuid
             WHERE ur.user_uuid = :user_uuid AND r.slug = :role
               AND u.status = 'active' AND u.deleted_at IS NULL
               AND r.status = 'active' AND r.deleted_at IS NULL
               AND (ur.expires_at IS NULL OR ur.expires_at >= :now)
             LIMIT 1"
        );
        $stmt->execute([
            'user_uuid' => $userUuid,
            'role' => self::SUPERUSER,
            'now' => gmdate('Y-m-d H:i:s'),
        ]);
        return $stmt->fetchColumn() !== false;
    }

    public function roleLevel(string $roleSlug): ?int
    {
        $row = $this->db->table('roles')->select(['level'])
            ->where('slug', '=', $roleSlug)
            ->whereNull('deleted_at')
            ->first();
        return is_array($row) ? (int) $row['level'] : null;
    }

    /** @return list<string> */
    public function rolePermissionSlugs(string $roleSlug): array
    {
        $stmt = $this->db->getPDO()->prepare(
            'SELECT p.slug FROM roles r
             JOIN role_permissions rp ON rp.role_uuid = r.uuid AND rp.deleted_at IS NULL
             JOIN permissions p ON p.uuid = rp.permission_uuid AND p.deleted_at IS NULL
             WHERE r.slug = :role AND r.deleted_at IS NULL'
        );
        $stmt->execute(['role' => $roleSlug]);
        return array_values(array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));
    }

    public function actorHoldsAllPermissionsOf(string $actorUuid, string $roleSlug): bool
    {
        foreach ($this->rolePermissionSlugs($roleSlug) as $permission) {
            try {
                if (!$this->aegis->can($actorUuid, $permission, 'thallo')) {
                    return false;
                }
            } catch (\Throwable) {
                return false;
            }
        }
        return true;
    }

    /** @return list<string> */
    public function crossWorkspaceRoleSlugs(): array
    {
        $stmt = $this->db->getPDO()->prepare(
            "SELECT DISTINCT r.slug FROM roles r
             JOIN role_permissions rp ON rp.role_uuid = r.uuid AND rp.deleted_at IS NULL
             JOIN permissions p ON p.uuid = rp.permission_uuid AND p.deleted_at IS NULL
             WHERE p.slug = :permission AND r.status = 'active' AND r.deleted_at IS NULL"
        );
        $stmt->execute(['permission' => self::ACCESS_ANY]);
        return array_values(array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));
    }

    /** @return list<string> */
    public function targetCrossWorkspaceRoleSlugs(string $userUuid): array
    {
        $stmt = $this->db->getPDO()->prepare(
            "SELECT DISTINCT r.slug FROM user_roles ur
             JOIN users u ON u.uuid = ur.user_uuid
             JOIN roles r ON r.uuid = ur.role_uuid
             JOIN role_permissions rp ON rp.role_uuid = r.uuid AND rp.deleted_at IS NULL
             JOIN permissions p ON p.uuid = rp.permission_uuid AND p.deleted_at IS NULL
             WHERE ur.user_uuid = :user_uuid AND p.slug = :permission
               AND u.status = 'active' AND u.deleted_at IS NULL
               AND r.status = 'active' AND r.deleted_at IS NULL
               AND (ur.expires_at IS NULL OR ur.expires_at >= :now)"
        );
        $stmt->execute([
            'user_uuid' => $userUuid,
            'permission' => self::ACCESS_ANY,
            'now' => gmdate('Y-m-d H:i:s'),
        ]);
        return array_values(array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN)));
    }

    public function activeSuperuserCount(): int
    {
        return $this->countHolders(
            "JOIN roles r ON r.uuid = ur.role_uuid AND r.slug = 'superuser'",
            [],
        );
    }

    public function activeCrossWorkspaceHolderCount(): int
    {
        return $this->countHolders(
            'JOIN roles r ON r.uuid = ur.role_uuid
             JOIN role_permissions rp ON rp.role_uuid = r.uuid AND rp.deleted_at IS NULL
             JOIN permissions p ON p.uuid = rp.permission_uuid
                AND p.slug = :permission AND p.deleted_at IS NULL',
            ['permission' => self::ACCESS_ANY],
        );
    }

    /** @param array<string,string> $params */
    private function countHolders(string $roleJoin, array $params): int
    {
        $stmt = $this->db->getPDO()->prepare(
            "SELECT COUNT(DISTINCT ur.user_uuid) FROM user_roles ur
             {$roleJoin}
             JOIN users u ON u.uuid = ur.user_uuid
             WHERE u.status = 'active' AND u.deleted_at IS NULL
               AND r.status = 'active' AND r.deleted_at IS NULL
               AND (ur.expires_at IS NULL OR ur.expires_at >= :now)"
        );
        $stmt->execute($params + ['now' => gmdate('Y-m-d H:i:s')]);
        return (int) $stmt->fetchColumn();
    }
}

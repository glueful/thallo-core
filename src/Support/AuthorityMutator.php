<?php

declare(strict_types=1);

namespace Thallo\Core\Support;

use Glueful\Database\Connection;
use Glueful\Extensions\Aegis\AegisPermissionProvider;
use Glueful\Helpers\Utils;

/** Authority-sensitive writes pinned to the continuity guard's database connection. */
final class AuthorityMutator
{
    public function __construct(
        private readonly Connection $db,
        private readonly AegisPermissionProvider $aegis,
    ) {
    }

    public function assignRole(string $userUuid, string $roleSlug): bool
    {
        $role = $this->roleUuid($roleSlug);
        if ($role === null) {
            return false;
        }
        $exists = $this->db->table('user_roles')
            ->where('user_uuid', '=', $userUuid)
            ->where('role_uuid', '=', $role)
            ->exists();
        if (!$exists) {
            $inserted = $this->db->table('user_roles')->insert([
                'uuid' => Utils::generateNanoID(12),
                'user_uuid' => $userUuid,
                'role_uuid' => $role,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ]);
            if (!$inserted) {
                return false;
            }
        }
        $this->invalidateAfterCommit($userUuid);
        return true;
    }

    public function revokeRole(string $userUuid, string $roleSlug): bool
    {
        $role = $this->roleUuid($roleSlug);
        if ($role === null) {
            return false;
        }
        $this->db->table('user_roles')
            ->where('user_uuid', '=', $userUuid)
            ->where('role_uuid', '=', $role)
            ->delete();
        $this->invalidateAfterCommit($userUuid);
        return true;
    }

    /** @param array<string,mixed> $attributes */
    public function updateUser(string $userUuid, array $attributes): bool
    {
        unset($attributes['uuid'], $attributes['password']);
        if ($attributes === []) {
            return true;
        }
        return $this->db->table('users')->where('uuid', '=', $userUuid)->update($attributes) > 0;
    }

    public function softDeleteUser(string $userUuid): bool
    {
        return $this->updateUser($userUuid, ['deleted_at' => gmdate('Y-m-d H:i:s')]);
    }

    private function roleUuid(string $slug): ?string
    {
        $row = $this->db->table('roles')->select(['uuid'])
            ->where('slug', '=', $slug)
            ->where('status', '=', 'active')
            ->whereNull('deleted_at')
            ->first();
        return is_array($row) ? (string) $row['uuid'] : null;
    }

    private function invalidateAfterCommit(string $userUuid): void
    {
        $this->db->afterCommit(fn () => $this->aegis->invalidateUserCache($userUuid));
    }
}

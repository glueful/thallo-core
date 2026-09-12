<?php

declare(strict_types=1);

use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Helpers\Utils;

/**
 * Add the `users.roles.manage` permission and grant it to the roles allowed to change other
 * users' role assignments.
 *
 * Editing a user's profile (`users.edit`) and changing their ROLES are different privileges:
 * assigning a role is a control-plane action that can escalate access, so it is gated separately.
 * UserAdminController now requires `users.roles.manage` (plus a level ceiling) before it will
 * apply `role_slugs`. Aegis's 003 seeds the role ladder and grants `users.*` to superuser (all)
 * and administrator, but this permission is Thallo-owned and added later, so it must be created and
 * granted explicitly here — a permission added after 003 is NOT retroactively granted to superuser.
 *
 * Runs AFTER Aegis's 003 (dependent priority), so the roles exist when we grant onto them.
 */
final class AddUsersRolesManagePermission implements MigrationInterface
{
    private const PERMISSION = 'users.roles.manage';
    private const LABEL = 'Assign and revoke user roles';

    /** Roles that receive role-management authority. */
    private const ROLES = ['superuser', 'administrator'];

    private Connection $db;

    public function up(SchemaBuilderInterface $schema): void
    {
        $this->db = new Connection();

        $permUuid = $this->ensurePermission();

        foreach (self::ROLES as $slug) {
            $role = $this->db->table('roles')->select(['uuid'])->where('slug', '=', $slug)->first();
            if ($role === null) {
                continue; // role absent (Aegis not installed / custom ladder) — skip.
            }
            $this->grant((string) $role['uuid'], $permUuid);
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $this->db = new Connection();

        $perm = $this->db->table('permissions')->select(['uuid'])
            ->where('slug', '=', self::PERMISSION)->first();
        if ($perm === null) {
            return;
        }
        $permUuid = (string) $perm['uuid'];
        $this->db->table('role_permissions')->where('permission_uuid', '=', $permUuid)->delete();
        $this->db->table('permissions')->where('uuid', '=', $permUuid)->delete();
    }

    public function getDescription(): string
    {
        return 'Add users.roles.manage and grant it to superuser + administrator.';
    }

    /** Create the permission row if missing (matched by slug); return its uuid. */
    private function ensurePermission(): string
    {
        $existing = $this->db->table('permissions')->select(['uuid'])
            ->where('slug', '=', self::PERMISSION)->first();
        if ($existing !== null) {
            return (string) $existing['uuid'];
        }
        $uuid = Utils::generateNanoID();
        $this->db->table('permissions')->insert([
            'uuid' => $uuid, 'slug' => self::PERMISSION, 'name' => self::LABEL,
            'category' => 'users', 'description' => self::LABEL, 'is_system' => true,
        ]);
        return $uuid;
    }

    /** Idempotently grant the permission to a role by the (role_uuid, permission_uuid) pair. */
    private function grant(string $roleUuid, string $permUuid): void
    {
        $exists = $this->db->table('role_permissions')
            ->where('role_uuid', '=', $roleUuid)
            ->where('permission_uuid', '=', $permUuid)
            ->count() > 0;
        if ($exists) {
            return;
        }
        $this->db->table('role_permissions')->insert([
            'uuid' => Utils::generateNanoID(),
            'role_uuid' => $roleUuid,
            'permission_uuid' => $permUuid,
        ]);
    }
}

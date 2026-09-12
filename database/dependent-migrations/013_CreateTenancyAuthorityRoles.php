<?php

declare(strict_types=1);

use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Helpers\Utils;

/** Canonical cross-workspace authority roles and grants. */
final class CreateTenancyAuthorityRoles implements MigrationInterface
{
    private const WORKSPACE_MANAGER_DESCRIPTION = 'Create, suspend, manage, and access every workspace. '
        . 'Does not include global user management or system administration.';

    private const PERMISSIONS = [
        'tenancy.manage' => 'Manage tenants',
        'tenancy.access_any' => 'Access any tenant',
    ];

    private Connection $db;

    public function up(SchemaBuilderInterface $schema): void
    {
        $this->db = new Connection();
        $permissions = $this->ensurePermissions();

        $workspaceManager = $this->ensureRole('workspace_manager', 'Workspace Manager', 90);
        $this->grant($workspaceManager, $permissions);

        $superuser = $this->roleUuid('superuser');
        if ($superuser !== null) {
            $this->grant($superuser, $permissions);
        }

        $administrator = $this->roleUuid('administrator');
        if ($administrator !== null) {
            $this->revoke($administrator, $permissions);
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $this->db = new Connection();
        $permissions = array_column(
            $this->db->table('permissions')->select(['uuid'])
                ->whereIn('slug', array_keys(self::PERMISSIONS))->get(),
            'uuid',
        );

        $administrator = $this->roleUuid('administrator');
        if ($administrator !== null && $permissions !== []) {
            $this->grant($administrator, $permissions);
        }

        $superuser = $this->roleUuid('superuser');
        if ($superuser !== null && $permissions !== []) {
            $this->revoke($superuser, $permissions);
        }

        $workspaceManager = $this->roleUuid('workspace_manager');
        if ($workspaceManager !== null) {
            $this->db->table('user_roles')->where('role_uuid', '=', $workspaceManager)->forceDelete();
            $this->db->table('role_permissions')->where('role_uuid', '=', $workspaceManager)->forceDelete();
            $this->db->table('roles')->where('uuid', '=', $workspaceManager)->forceDelete();
        }
    }

    public function getDescription(): string
    {
        return 'Create workspace_manager and grant cross-workspace authority to it and superuser.';
    }

    /** @return array<string,string> */
    private function ensurePermissions(): array
    {
        $bySlug = [];
        foreach (
            $this->db->table('permissions')->select(['uuid', 'slug'])
                ->whereIn('slug', array_keys(self::PERMISSIONS))->get() as $row
        ) {
            $bySlug[(string) $row['slug']] = (string) $row['uuid'];
        }

        $insert = [];
        foreach (self::PERMISSIONS as $slug => $name) {
            if (isset($bySlug[$slug])) {
                continue;
            }
            $uuid = Utils::generateNanoID(12);
            $bySlug[$slug] = $uuid;
            $insert[] = [
                'uuid' => $uuid,
                'slug' => $slug,
                'name' => $name,
                'category' => 'tenancy',
                'description' => $name,
                'is_system' => true,
            ];
        }
        if ($insert !== []) {
            $this->db->table('permissions')->insertBatch($insert);
        }
        return $bySlug;
    }

    private function ensureRole(string $slug, string $name, int $level): string
    {
        $uuid = $this->roleUuid($slug);
        if ($uuid !== null) {
            $this->db->table('roles')->where('uuid', '=', $uuid)->update([
                'name' => $name,
                'description' => self::WORKSPACE_MANAGER_DESCRIPTION,
                'level' => $level,
                'is_system' => true,
                'status' => 'active',
            ]);
            return $uuid;
        }

        $uuid = Utils::generateNanoID(12);
        $this->db->table('roles')->insert([
            'uuid' => $uuid,
            'name' => $name,
            'slug' => $slug,
            'description' => self::WORKSPACE_MANAGER_DESCRIPTION,
            'level' => $level,
            'is_system' => true,
            'status' => 'active',
        ]);
        return $uuid;
    }

    private function roleUuid(string $slug): ?string
    {
        $row = $this->db->table('roles')->select(['uuid'])->where('slug', '=', $slug)->first();
        return is_array($row) ? (string) $row['uuid'] : null;
    }

    /** @param array<string,string> $permissions */
    private function grant(string $roleUuid, array $permissions): void
    {
        $existing = [];
        foreach (
            $this->db->table('role_permissions')->select(['permission_uuid'])
                ->where('role_uuid', '=', $roleUuid)->get() as $row
        ) {
            $existing[(string) $row['permission_uuid']] = true;
        }

        $insert = [];
        foreach ($permissions as $permissionUuid) {
            if (isset($existing[$permissionUuid])) {
                continue;
            }
            $insert[] = [
                'uuid' => Utils::generateNanoID(12),
                'role_uuid' => $roleUuid,
                'permission_uuid' => $permissionUuid,
            ];
        }
        if ($insert !== []) {
            $this->db->table('role_permissions')->insertBatch($insert);
        }
    }

    /** @param array<string,string> $permissions */
    private function revoke(string $roleUuid, array $permissions): void
    {
        foreach ($permissions as $permissionUuid) {
            $this->db->table('role_permissions')
                ->where('role_uuid', '=', $roleUuid)
                ->where('permission_uuid', '=', $permissionUuid)
                ->forceDelete();
        }
    }
}

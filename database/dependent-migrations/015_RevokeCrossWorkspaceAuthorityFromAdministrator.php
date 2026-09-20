<?php

declare(strict_types=1);

use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Take cross-workspace authority back off the `administrator` role.
 *
 * Migration 013 revoked `tenancy.access_any` and `tenancy.manage` from administrators — that
 * authority is the superuser's and the workspace manager's — but InstallRoleGrants, which
 * `thallo:provision` re-runs on every upgrade, granted the role every permission it did not
 * currently hold, and so handed both back. They are withheld there now; this removes what was
 * re-granted meanwhile. An administrator who should reach every workspace is given the
 * `workspace_manager` role, which is what it is for.
 *
 * Touches that one role and those two permissions. Idempotent; nothing to restore on `down`
 * (013's own `down` is what returns them).
 */
final class RevokeCrossWorkspaceAuthorityFromAdministrator implements MigrationInterface
{
    private const PERMISSIONS = ['tenancy.access_any', 'tenancy.manage'];

    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('roles') || !$schema->hasTable('role_permissions')) {
            return;
        }
        $db = new Connection();
        $role = $db->table('roles')->select(['uuid'])->where('slug', '=', 'administrator')->first();
        if (!is_array($role)) {
            return;
        }
        $permissions = array_column(
            $db->table('permissions')->select(['uuid'])->whereIn('slug', self::PERMISSIONS)->get(),
            'uuid',
        );
        foreach ($permissions as $permissionUuid) {
            $db->table('role_permissions')
                ->where('role_uuid', '=', (string) $role['uuid'])
                ->where('permission_uuid', '=', (string) $permissionUuid)
                ->forceDelete();
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        // Nothing to restore: the grants this removes were never meant to exist.
    }

    public function getDescription(): string
    {
        return 'Revoke tenancy.access_any and tenancy.manage from the administrator role (re-granted by provision).';
    }
}

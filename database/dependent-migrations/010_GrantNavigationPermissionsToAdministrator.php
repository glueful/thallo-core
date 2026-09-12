<?php

declare(strict_types=1);

use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Helpers\Utils;

/**
 * Grant the navigation permission to Aegis's `administrator` role. The
 * `glueful/thallo-navigation` pack declares the permission row (its own seed migration);
 * granting onto roles is the HOST APP's decision — this dependent migration runs after
 * Aegis has created the role.
 */
final class GrantNavigationPermissionsToAdministrator implements MigrationInterface
{
    private const PERMISSIONS = [
        'navigation.manage' => 'Manage navigation menus',
    ];

    private const ROLE = 'administrator';

    private Connection $db;

    public function up(SchemaBuilderInterface $schema): void
    {
        $this->db = new Connection();

        // Ensure the permission rows exist (create-if-missing, matched by slug — harmless
        // duplication guard against ordering with the pack's own seed).
        $permUuids = $this->ensurePermissions();

        // Resolve the administrator role (lookup only — never create an Aegis role here).
        $role = $this->db->table('roles')->select(['uuid'])->where('slug', '=', self::ROLE)->first();
        if ($role === null) {
            return; // Aegis not installed / administrator absent — nothing to grant onto.
        }

        $this->assign((string) $role['uuid'], $permUuids);
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $this->db = new Connection();

        // Remove only the grants we added (leave the permission rows — the pack owns them).
        $permUuids = array_column(
            $this->db->table('permissions')->select(['uuid'])
                ->whereIn('slug', array_keys(self::PERMISSIONS))->get(),
            'uuid'
        );
        $role = $this->db->table('roles')->select(['uuid'])->where('slug', '=', self::ROLE)->first();
        if ($role === null || $permUuids === []) {
            return;
        }
        $this->db->table('role_permissions')
            ->where('role_uuid', '=', (string) $role['uuid'])
            ->whereIn('permission_uuid', $permUuids)
            ->delete();
    }

    public function getDescription(): string
    {
        return 'Grant navigation.manage to the administrator role.';
    }

    /**
     * Insert any missing navigation permission rows; return slug => uuid.
     *
     * @return array<string,string>
     */
    private function ensurePermissions(): array
    {
        $bySlug = [];
        foreach (
            $this->db->table('permissions')->select(['uuid', 'slug'])
            ->whereIn('slug', array_keys(self::PERMISSIONS))->get() as $row
        ) {
            $bySlug[$row['slug']] = $row['uuid'];
        }
        $insert = [];
        foreach (self::PERMISSIONS as $slug => $label) {
            if (!isset($bySlug[$slug])) {
                $uuid = Utils::generateNanoID();
                $bySlug[$slug] = $uuid;
                $insert[] = [
                    'uuid' => $uuid, 'slug' => $slug, 'name' => $label,
                    'category' => 'navigation', 'description' => $label, 'is_system' => true,
                ];
            }
        }
        if ($insert !== []) {
            $this->db->table('permissions')->insertBatch($insert);
        }
        return $bySlug;
    }

    /**
     * Idempotently grant every permission to the role by the (role_uuid, permission_uuid) pair.
     *
     * @param array<string,string> $permUuids slug => uuid
     */
    private function assign(string $roleUuid, array $permUuids): void
    {
        $existing = [];
        foreach (
            $this->db->table('role_permissions')->select(['permission_uuid'])
            ->where('role_uuid', '=', $roleUuid)->get() as $row
        ) {
            $existing[$row['permission_uuid']] = true;
        }
        $new = [];
        foreach ($permUuids as $permUuid) {
            if (!isset($existing[$permUuid])) {
                $new[] = [
                    'uuid' => Utils::generateNanoID(),
                    'role_uuid' => $roleUuid,
                    'permission_uuid' => $permUuid,
                ];
            }
        }
        if ($new !== []) {
            $this->db->table('role_permissions')->insertBatch($new);
        }
    }
}

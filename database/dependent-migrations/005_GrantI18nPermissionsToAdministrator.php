<?php

declare(strict_types=1);

use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Helpers\Utils;

/**
 * Grant the i18n admin permissions to Aegis's `administrator` role so the Thallo admin can
 * manage languages/translations.
 *
 * The `glueful/i18n` extension declares its permissions (`i18n.view/manage/import/export`) in the
 * framework permission catalog and enforces them via `RequireI18nPermission` → `PermissionManager`,
 * but it ships no DB seed and its own migrations run BEFORE Aegis (so it cannot grant onto roles
 * Aegis hasn't created yet). This dependent migration runs AFTER Aegis's 003, so it:
 *   - ensures the four `i18n.*` permission rows exist (idempotent — harmless if i18n later seeds them), and
 *   - grants all four to the `administrator` role.
 *
 * Without it every `/i18n/*` admin route returns 403 even for a full administrator, because the
 * permission the gate checks was never granted (or never persisted).
 */
final class GrantI18nPermissionsToAdministrator implements MigrationInterface
{
    /** i18n permission slugs the Thallo admin needs → label. */
    private const PERMISSIONS = [
        'i18n.view' => 'View locales and translations',
        'i18n.manage' => 'Create and edit locales and translations',
        'i18n.import' => 'Import translation files',
        'i18n.export' => 'Export translation files',
    ];

    /** Role that receives full i18n management. */
    private const ROLE = 'administrator';

    private Connection $db;

    public function up(SchemaBuilderInterface $schema): void
    {
        $this->db = new Connection();

        // Ensure the i18n permission rows exist (create-if-missing, matched by slug).
        $permUuids = $this->ensurePermissions();

        // Resolve the administrator role (lookup only — never create an Aegis role here).
        $role = $this->db->table('roles')->select(['uuid'])->where('slug', '=', self::ROLE)->first();
        if ($role === null) {
            return; // Aegis not installed / administrator absent — nothing to grant onto.
        }
        $roleUuid = (string) $role['uuid'];

        $this->assign($roleUuid, $permUuids);
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $this->db = new Connection();

        // Remove only the grants we added (leave the permission rows — i18n may own them).
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
        return 'Grant i18n.view/manage/import/export to the administrator role.';
    }

    /**
     * Insert any missing i18n permission rows; return slug => uuid for all four.
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
                    'category' => 'i18n', 'description' => $label, 'is_system' => true,
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

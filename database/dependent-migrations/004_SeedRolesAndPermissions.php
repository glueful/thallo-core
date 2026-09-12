<?php

declare(strict_types=1);

use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Helpers\Utils;

/**
 * Thallo's content permissions + the `editor` role, layered onto Aegis's standard roles.
 *
 * Aegis (003) owns the role ladder (superuser / administrator / user) and the core `content.*`
 * permissions (view/create/edit/delete). Thallo does NOT define its own admin/viewer roles — the
 * first admin uses Aegis's `administrator` and read-only users use Aegis's `user`. This migration
 * only:
 *   - adds the content-specific permissions Thallo gates on (`content.publish/manage/routes`),
 *   - grants them onto the existing `administrator` role, and
 *   - seeds a Thallo-owned `editor` role (content view/create/edit/publish).
 *
 * Runs as a dependent migration, i.e. AFTER Aegis's 003, so `content.view/create/edit` already
 * exist when we reference them here.
 */
final class SeedRolesAndPermissions implements MigrationInterface
{
    /**
     * Permissions ENSURED by this migration (created if missing + removed on
     * rollback). slug => [label, category]. Most are Thallo-owned; the email
     * one is DECLARED by glueful/email-notification's permissions() catalog —
     * but the Aegis catalog sync is CLI-driven, not part of migrations, so a
     * grant-only reference would silently no-op on a fresh database. The
     * create-if-missing here is idempotent by slug; a later `permissions:sync`
     * reconciles the catalog metadata.
     */
    private const PERMISSIONS = [
        'content.publish' => ['Publish and unpublish content', 'content'],
        'content.manage' => ['Manage content types', 'content'],
        'content.routes' => ['Manage redirects and SEO routes', 'content'],
        'email.templates.manage' => ['Manage email templates & settings', 'email'],
    ];

    /** Thallo-owned roles. slug => [name, level, granted permission slugs (Aegis + Thallo)] */
    private const ROLES = [
        'editor' => ['Editor', 50, [
            'content.view', 'content.create', 'content.edit', 'content.publish',
        ]],
    ];

    /** Thallo permissions granted onto EXISTING Aegis roles. aegis role slug => [perm slugs] */
    private const ROLE_GRANTS = [
        'administrator' => [
            'content.publish', 'content.manage', 'content.routes',
            // Collections admin permissions: the rows are declared by the thallo-collections pack's
            // own migration; this grants them to administrator. Aegis seeds the role before this runs.
            'collections.manage', 'collections.schema.manage', 'collections.data.manage',
            'analytics.read',
            'seo.manage',
            // Email admin (glueful/email-notification): templates + transport settings.
            'email.templates.manage',
        ],
    ];

    private Connection $db;

    public function up(SchemaBuilderInterface $schema): void
    {
        $this->db = new Connection();

        // Create the ensured permissions (idempotent by slug).
        $this->ensureRows('permissions', array_map(
            static fn(string $slug, array $def): array => [
                'slug' => $slug, 'name' => $def[0], 'category' => $def[1],
                'description' => $def[0], 'is_system' => true,
            ],
            array_keys(self::PERMISSIONS),
            array_values(self::PERMISSIONS),
        ));

        // Create Thallo's editor role (idempotent), then resolve the existing Aegis roles we add to.
        $roleUuids = $this->ensureRows('roles', array_map(
            static fn(string $slug, array $r): array => [
                'slug' => $slug, 'name' => $r[0], 'description' => $r[0],
                'level' => $r[1], 'is_system' => true, 'status' => 'active',
            ],
            array_keys(self::ROLES),
            array_values(self::ROLES),
        ));
        $roleUuids += $this->lookupUuids('roles', array_keys(self::ROLE_GRANTS));

        // Build role => [perm slugs] from both sources, then resolve every referenced permission.
        $grantsByRole = [];
        foreach (self::ROLES as $slug => [, , $grants]) {
            $grantsByRole[$slug] = $grants;
        }
        foreach (self::ROLE_GRANTS as $slug => $grants) {
            $grantsByRole[$slug] = array_merge($grantsByRole[$slug] ?? [], $grants);
        }
        $permSlugs = array_values(array_unique(array_merge(...array_values($grantsByRole))));
        $permUuids = $this->lookupUuids('permissions', $permSlugs);

        $this->assign($roleUuids, $permUuids, $grantsByRole);
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $this->db = new Connection();

        // Remove Thallo's permissions from every role (incl. administrator), then the perms.
        $permUuids = array_column(
            $this->db->table('permissions')->select(['uuid'])
                ->whereIn('slug', array_keys(self::PERMISSIONS))->get(),
            'uuid'
        );
        if ($permUuids !== []) {
            $this->db->table('role_permissions')->whereIn('permission_uuid', $permUuids)->delete();
        }

        // Remove the editor role's grants + the role itself (leaving Aegis roles untouched).
        $roleUuids = array_column(
            $this->db->table('roles')->select(['uuid'])
                ->whereIn('slug', array_keys(self::ROLES))->get(),
            'uuid'
        );
        if ($roleUuids !== []) {
            $this->db->table('role_permissions')->whereIn('role_uuid', $roleUuids)->delete();
        }

        $this->db->table('permissions')->whereIn('slug', array_keys(self::PERMISSIONS))->delete();
        $this->db->table('roles')->whereIn('slug', array_keys(self::ROLES))->delete();
    }

    public function getDescription(): string
    {
        return 'Seed Thallo content permissions + the editor role onto Aegis standard roles.';
    }

    /**
     * Insert rows that don't already exist (matched by slug); return slug => uuid for all.
     *
     * @param list<array<string,mixed>> $rows each carries a 'slug'
     * @return array<string,string>
     */
    private function ensureRows(string $table, array $rows): array
    {
        $slugs = array_column($rows, 'slug');
        $bySlug = $this->lookupUuids($table, $slugs);
        $insert = [];
        foreach ($rows as $row) {
            if (!isset($bySlug[$row['slug']])) {
                $row['uuid'] = Utils::generateNanoID();
                $bySlug[$row['slug']] = $row['uuid'];
                $insert[] = $row;
            }
        }
        if ($insert !== []) {
            $this->db->table($table)->insertBatch($insert);
        }
        return $bySlug;
    }

    /**
     * Look up existing rows by slug (never creates). Returns slug => uuid.
     *
     * @param list<string> $slugs
     * @return array<string,string>
     */
    private function lookupUuids(string $table, array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }
        $out = [];
        foreach ($this->db->table($table)->select(['uuid', 'slug'])->whereIn('slug', $slugs)->get() as $r) {
            $out[$r['slug']] = $r['uuid'];
        }
        return $out;
    }

    /**
     * Idempotently assign permissions to roles by the (role_uuid, permission_uuid) pair.
     *
     * @param array<string,string>       $roleUuids    role slug => uuid
     * @param array<string,string>       $permUuids    perm slug => uuid
     * @param array<string,list<string>> $grantsByRole role slug => list of perm slugs
     */
    private function assign(array $roleUuids, array $permUuids, array $grantsByRole): void
    {
        $existing = [];
        foreach ($this->db->table('role_permissions')->select(['role_uuid', 'permission_uuid'])->get() as $row) {
            $existing[$row['role_uuid'] . '|' . $row['permission_uuid']] = true;
        }
        $new = [];
        foreach ($grantsByRole as $roleSlug => $permSlugs) {
            $roleUuid = $roleUuids[$roleSlug] ?? null;
            if ($roleUuid === null) {
                continue;
            }
            foreach ($permSlugs as $permSlug) {
                $permUuid = $permUuids[$permSlug] ?? null;
                if ($permUuid === null) {
                    continue;
                }
                $pair = $roleUuid . '|' . $permUuid;
                if (!isset($existing[$pair])) {
                    $new[] = [
                        'uuid' => Utils::generateNanoID(),
                        'role_uuid' => $roleUuid,
                        'permission_uuid' => $permUuid,
                    ];
                    $existing[$pair] = true;
                }
            }
        }
        if ($new !== []) {
            $this->db->table('role_permissions')->insertBatch($new);
        }
    }
}

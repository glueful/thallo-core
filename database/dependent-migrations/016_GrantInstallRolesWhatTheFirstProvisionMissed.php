<?php

declare(strict_types=1);

use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Glueful\Helpers\Utils;

/**
 * Repairs the install roles of a site whose first `thallo:provision` granted them almost nothing.
 *
 * Aegis's own migration creates `superuser` and `administrator` holding its permissions, so the
 * first provision on a fresh install found the roles holding grants and treated the site as one
 * upgrading into the install-role ledger: every permission that existed before its catalog sync
 * — the ones packs seed by migration, such as commerce.view, commerce.manage, templates.manage and,
 * on superuser, content.manage — was recorded as offered and never granted, and every later
 * provision skipped them. InstallRoleGrants now offers a not-yet-installed site everything; this
 * migration gives a site that went through the old first run what it missed:
 *   - superuser: every permission it lacks — it is the role that holds everything;
 *   - administrator: commerce.view, commerce.manage and templates.manage (it already held the
 *     rest its own dependent migrations grant; system.config and the tenancy permissions stay
 *     withheld, as InstallRoleGrants::ROLE_EXCLUSIONS withholds them).
 *
 * Runs once (the migration ledger), so a grant revoked afterwards stays revoked. Idempotent:
 * matched by the (role, permission) pair.
 */
final class GrantInstallRolesWhatTheFirstProvisionMissed implements MigrationInterface
{
    /** What administrator missed; the permission rows are the packs' and core's, never created here. */
    private const ADMINISTRATOR = ['commerce.view', 'commerce.manage', 'templates.manage'];

    private Connection $db;

    public function up(SchemaBuilderInterface $schema): void
    {
        $this->db = new Connection();

        $all = [];
        foreach ($this->db->table('permissions')->select(['uuid', 'slug'])->get() as $row) {
            $all[(string) $row['slug']] = (string) $row['uuid'];
        }

        $this->grant('superuser', array_values($all));
        $this->grant('administrator', array_values(array_intersect_key($all, array_flip(self::ADMINISTRATOR))));
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        // Not reversed: which grants were missing, and which an operator made since, is not
        // recorded — taking them back could strip a site's own decisions.
    }

    public function getDescription(): string
    {
        return 'Give superuser every permission, and administrator commerce.view, commerce.manage and '
            . 'templates.manage, where the first provision recorded them as offered and granted none.';
    }

    /** @param list<string> $permissionUuids */
    private function grant(string $roleSlug, array $permissionUuids): void
    {
        $role = $this->db->table('roles')->select(['uuid'])->where('slug', '=', $roleSlug)->first();
        if ($role === null || $permissionUuids === []) {
            return; // Aegis not installed, or the role absent: nothing to grant onto.
        }
        $roleUuid = (string) $role['uuid'];

        $held = [];
        foreach (
            $this->db->table('role_permissions')->select(['permission_uuid'])
            ->where('role_uuid', '=', $roleUuid)->get() as $row
        ) {
            $held[(string) $row['permission_uuid']] = true;
        }
        $new = [];
        foreach ($permissionUuids as $permissionUuid) {
            if (!isset($held[$permissionUuid])) {
                $new[] = [
                    'uuid' => Utils::generateNanoID(),
                    'role_uuid' => $roleUuid,
                    'permission_uuid' => $permissionUuid,
                ];
            }
        }
        if ($new !== []) {
            $this->db->table('role_permissions')->insertBatch($new);
        }
    }
}

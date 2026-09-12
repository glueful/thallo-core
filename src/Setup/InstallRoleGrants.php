<?php

declare(strict_types=1);

namespace Thallo\Core\Setup;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Aegis\Repositories\PermissionRepository;
use Glueful\Extensions\Aegis\Repositories\RolePermissionRepository;
use Glueful\Extensions\Aegis\Repositories\RoleRepository;
use Glueful\Extensions\ExtensionManager;
use Glueful\Interfaces\Permission\PermissionCatalogSyncInterface;
use Glueful\Permissions\Catalog\PermissionRegistry;

/**
 * Makes "superuser has full access" true.
 *
 * Aegis seeds the install roles with its own permissions only; Thallo's packs seed theirs by
 * migration and Thallo's core catalog is declared through the provider — and nothing granted
 * any of it to a role, so the first admin could not manage content models, read the audit
 * log or see analytics. apply() persists the declared catalog into the RBAC provider (the same
 * mechanics as `permissions:sync`) and then grants every permission row to `superuser`, and
 * every row but `system.config` to `administrator`. Additive and idempotent: web setup and
 * `thallo:create-admin` run it once at install, `thallo:provision` re-runs it so a pack added
 * on upgrade reaches the install roles too. Operator revocations are not re-applied blindly —
 * only rows a role has never held are granted.
 */
final class InstallRoleGrants
{
    /** @var array<string, list<string>> role slug => permission slugs withheld from that role */
    public const ROLE_EXCLUSIONS = [
        'superuser' => [],
        'administrator' => ['system.config'],
    ];

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ExtensionManager $extensions,
    ) {
    }

    public function apply(): InstallRoleGrantsReport
    {
        $declared = $this->syncCatalog();

        $granted = [];
        foreach (self::ROLE_EXCLUSIONS as $role => $except) {
            $granted[$role] = $this->grantAll($role, $except);
        }

        return new InstallRoleGrantsReport($declared, $granted);
    }

    /** Persist every declared permission (framework core, app, extensions) into the provider. */
    private function syncCatalog(): int
    {
        $container = $this->context->getContainer();
        $this->extensions->aggregatePermissionCatalog();
        $registry = $container->get(PermissionRegistry::class);

        $provider = $this->syncCapableProvider();

        $permissions = array_map(static fn ($p): array => $p->toArray(), $registry->permissions());
        $roles = array_map(static fn ($r): array => $r->toArray(), $registry->roles());
        $provider->syncCatalog(array_values($permissions), array_values($roles));

        return count($permissions);
    }

    /**
     * The active RBAC provider — activated here when boot skipped it. Aegis decides at BOOT
     * whether to activate (the RBAC tables must already exist), and `thallo:provision` runs the
     * migrations that create them in the same process, so on a fresh install the provider is
     * registered in the container but not yet active. Resolve and activate it, exactly as the
     * extension's own boot would once the tables exist.
     */
    private function syncCapableProvider(): PermissionCatalogSyncInterface
    {
        $container = $this->context->getContainer();
        $manager = $container->has('permission.manager') ? $container->get('permission.manager') : null;

        $active = $manager?->getProvider();
        if ($active instanceof PermissionCatalogSyncInterface) {
            return $active;
        }

        $aegis = 'Glueful\\Extensions\\Aegis\\AegisPermissionProvider';
        if ($manager !== null && class_exists($aegis) && $container->has($aegis)) {
            $provider = $container->get($aegis);
            if ($provider instanceof PermissionCatalogSyncInterface) {
                $rbac = (array) config($this->context, 'rbac', []);
                $manager->registerProviders(['rbac' => $provider]);
                $manager->setProvider($provider, [
                    'cache_enabled' => $rbac['permissions']['cache_enabled'] ?? true,
                    'cache_ttl' => $rbac['permissions']['cache_ttl'] ?? 3600,
                    'cache_prefix' => $rbac['permissions']['cache_prefix'] ?? 'rbac:',
                    'enable_hierarchy' => $rbac['roles']['inherit_permissions'] ?? true,
                    'enable_inheritance' => $rbac['permissions']['inheritance_enabled'] ?? true,
                    'max_hierarchy_depth' => $rbac['roles']['max_hierarchy_depth'] ?? 10,
                ]);
                return $provider;
            }
        }

        throw new \RuntimeException(
            'No persistent RBAC provider with catalog sync is available — enable glueful/aegis and run migrations.'
        );
    }

    /**
     * @param list<string> $except
     * @return int permissions newly granted
     */
    private function grantAll(string $roleSlug, array $except): int
    {
        $roles = new RoleRepository(null, $this->context);
        $permissions = new PermissionRepository(null, $this->context);
        $rolePermissions = new RolePermissionRepository(null, $this->context);

        $role = $roles->findRoleBySlug($roleSlug);
        if ($role === null) {
            throw new \RuntimeException("Seeded role '{$roleSlug}' is missing — run `php glueful migrate:run`.");
        }
        $roleUuid = $role->getUuid();

        $held = [];
        foreach ($rolePermissions->getRolePermissions($roleUuid) as $rp) {
            $held[$rp->getPermissionUuid()] = true;
        }

        $granted = 0;
        foreach ($permissions->findAllPermissions() as $permission) {
            if (in_array($permission->getSlug(), $except, true) || isset($held[$permission->getUuid()])) {
                continue;
            }
            $rolePermissions->assignPermissionToRole($roleUuid, $permission->getUuid());
            $granted++;
        }

        if ($granted > 0) {
            // role_permissions was written directly — cached decisions are stale.
            $provider = $this->syncCapableProvider();
            if (method_exists($provider, 'invalidateAllCache')) {
                $provider->invalidateAllCache();
            }
        }

        return $granted;
    }
}

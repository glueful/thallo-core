<?php

declare(strict_types=1);

namespace Thallo\Core\Setup;

use Thallo\Contracts\Settings\SystemChannel;
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
 * mechanics as `permissions:sync`) and then grants every permission row to `superuser`, and to
 * `administrator` every row not WITHHELD from it (ROLE_EXCLUSIONS). Additive and idempotent: web
 * setup and `thallo:create-admin` run it once at install, `thallo:provision` re-runs it so a pack
 * added on upgrade reaches the install roles too.
 *
 * Each permission is offered to a role once. A ledger in the system channel records what each
 * role has been offered, so a later provision grants only permissions that are new since, and a
 * revocation made in between — by an operator or a migration — stays revoked. With no ledger yet:
 * a role that holds nothing (a fresh install) is offered everything; a role that holds grants (a
 * site upgrading into the ledger) takes every permission that existed before this run's catalog
 * sync as already offered, and is granted only what the sync added. ROLE_EXCLUSIONS still withhold
 * a permission from a role outright.
 */
final class InstallRoleGrants
{
    /** @var array<string, list<string>> role slug => permission slugs withheld from that role */
    /** System-channel key: JSON map of role slug => permission slugs already offered to it. */
    public const LEDGER_KEY = 'install_role_grants.offered';

    public const ROLE_EXCLUSIONS = [
        'superuser' => [],
        // An administrator runs ONE site: not the system's configuration, and not authority ACROSS
        // workspaces. The authority migration (013) takes both tenancy permissions from this role
        // and gives them to `workspace_manager`; withheld here too, or every provision — every
        // upgrade — handed them back, and an administrator could enter any workspace.
        'administrator' => ['system.config', 'tenancy.access_any', 'tenancy.manage'],
    ];

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ExtensionManager $extensions,
    ) {
    }

    public function apply(): InstallRoleGrantsReport
    {
        $before = $this->permissionSlugs();
        $declared = $this->syncCatalog();
        $ledger = $this->ledger();

        $granted = [];
        foreach (self::ROLE_EXCLUSIONS as $role => $except) {
            [$granted[$role], $ledger[$role]] = $this->grantNew($role, $except, $ledger[$role] ?? null, $before);
        }
        $this->channel()->put(self::LEDGER_KEY, (string) json_encode($ledger, JSON_THROW_ON_ERROR));

        return new InstallRoleGrantsReport($declared, $granted);
    }

    /** @return array<string, list<string>> */
    private function ledger(): array
    {
        $raw = $this->channel()->get(self::LEDGER_KEY);
        $decoded = $raw === null ? null : json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        $ledger = [];
        foreach ($decoded as $role => $slugs) {
            if (is_string($role) && is_array($slugs)) {
                $ledger[$role] = array_values(array_filter($slugs, 'is_string'));
            }
        }
        return $ledger;
    }

    private function channel(): SystemChannel
    {
        return $this->context->getContainer()->get(SystemChannel::class);
    }

    /** @return list<string> every permission slug in the provider now */
    private function permissionSlugs(): array
    {
        return array_map(
            static fn ($permission): string => $permission->getSlug(),
            (new PermissionRepository(null, $this->context))->findAllPermissions(),
        );
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
     * Grants the role what it has not been offered yet, and returns the count and the new record.
     *
     * @param list<string> $except
     * @param list<string>|null $offered the role's ledger entry; null when it has none yet
     * @param list<string> $before the permission slugs that existed before this run's catalog sync
     * @return array{0: int, 1: list<string>}
     */
    private function grantNew(string $roleSlug, array $except, ?array $offered, array $before): array
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
        // No record yet: a role holding nothing is a fresh install and is offered everything; a
        // role holding grants has, in effect, been offered whatever existed before this run.
        $offered ??= $held === [] ? [] : $before;
        $offeredSet = array_fill_keys($offered, true);

        $granted = 0;
        foreach ($permissions->findAllPermissions() as $permission) {
            $slug = $permission->getSlug();
            if (isset($offeredSet[$slug]) || in_array($slug, $except, true)) {
                continue;
            }
            $offeredSet[$slug] = true;
            if (!isset($held[$permission->getUuid()])) {
                $rolePermissions->assignPermissionToRole($roleUuid, $permission->getUuid());
                $granted++;
            }
        }

        if ($granted > 0) {
            // role_permissions was written directly — cached decisions are stale.
            $provider = $this->syncCapableProvider();
            if (method_exists($provider, 'invalidateAllCache')) {
                $provider->invalidateAllCache();
            }
        }

        return [$granted, array_keys($offeredSet)];
    }
}

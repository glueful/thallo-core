<?php

declare(strict_types=1);

namespace Thallo\Core\Setup;

use Glueful\Database\Connection;
use Thallo\Tenancy\System\SystemFlags;
use Thallo\Contracts\Settings\SystemChannel;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Aegis\Repositories\PermissionRepository;
use Glueful\Extensions\Aegis\Repositories\RolePermissionRepository;
use Glueful\Extensions\Aegis\Repositories\RoleRepository;
use Glueful\Extensions\ExtensionManager;
use Glueful\Interfaces\Permission\PermissionCatalogSyncInterface;
use Glueful\Permissions\Catalog\PermissionRegistry;
use Thallo\Core\Capabilities\CapabilityStateStore;
use Thallo\Core\Capabilities\FeatureManagementPolicy;

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
 * a site not installed yet (a fresh install, whatever Aegis seeded its roles with) or a role that
 * holds nothing is offered everything; an installed site's role that holds grants (a site upgrading
 * into the ledger) takes every permission that existed before this run's catalog sync as already
 * offered, and is granted only what the sync added. ROLE_EXCLUSIONS still withhold
 * a permission from a role outright.
 *
 * A permission managed by the engine of an activation capability that is not on is synced but
 * neither granted nor recorded as offered: that capability's activation grants it (spec §7.3a — no
 * grants before the activation). The activation passes its capability, so its engine's permissions
 * are offered then.
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

    /** @param string|null $activating the activation capability granting its permissions now */
    public function apply(?string $activating = null): InstallRoleGrantsReport
    {
        $before = $this->permissionSlugs();
        $declared = $this->syncCatalog();
        $withheld = $this->withheldSlugs($activating);

        // The decision and its record are one serialized transaction: under the grants lock the
        // ledger is read fresh, the grants are made and the ledger is written back. Two runs (two
        // provisions, an activation and a provision) can't overwrite each other's ledger entries,
        // and a run can't act on a ledger read before another run's grants and an operator's
        // revocation. An activation calls this inside its fenced row lock, so its ownership is held
        // through the whole transaction (lock order: activation row, then this lock).
        $db = $this->context->getContainer()->get(Connection::class);
        $granted = $db->transaction(function () use ($db, $before, $withheld): array {
            $db->getPDO()->exec("SELECT pg_advisory_xact_lock(hashtext('thallo:install-role-grants'))");
            $channel = $this->channel();
            if ($channel instanceof SystemFlags) {
                $channel->clearCache();
            }
            $ledger = $this->ledger();
            $this->pauseForTestsAfterLedgerRead();

            $granted = [];
            foreach (self::ROLE_EXCLUSIONS as $role => $except) {
                [$granted[$role], $ledger[$role]] = $this->grantNew(
                    $role,
                    $except,
                    $ledger[$role] ?? null,
                    $before,
                    $withheld,
                );
            }
            $channel->put(self::LEDGER_KEY, (string) json_encode($ledger, JSON_THROW_ON_ERROR));
            return $granted;
        });

        return new InstallRoleGrantsReport($declared, $granted);
    }

    /** Test seam (APP_ENV=testing only): pause right after the ledger read, holding the lock. */
    private function pauseForTestsAfterLedgerRead(): void
    {
        if ($this->context->getEnvironment() !== 'testing' || getenv('THALLO_TEST_PAUSE_AFTER_LEDGER_READ') !== '1') {
            return;
        }
        fwrite(STDOUT, "ledger-read\n");
        fflush(STDOUT);
        fgets(STDIN);
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

    private function installed(): bool
    {
        return $this->channel()->get('installed') === '1';
    }

    private function channel(): SystemChannel
    {
        return $this->context->getContainer()->get(SystemChannel::class);
    }

    /**
     * The permissions of every activation capability's engine whose capability is not on (its
     * stored switch), other than the one being activated.
     *
     * @return list<string>
     */
    private function withheldSlugs(?string $activating): array
    {
        $container = $this->context->getContainer();
        if (!$container->has(FeatureManagementPolicy::class) || !$container->has(CapabilityStateStore::class)) {
            return [];
        }
        $policy = $container->get(FeatureManagementPolicy::class);
        $states = $container->get(CapabilityStateStore::class);
        $packages = [];
        foreach ($policy->activationCapabilities() as $id) {
            $package = $policy->engineOf($id)['package'] ?? null;
            if ($id !== $activating && is_string($package) && $states->storedFresh($id) !== true) {
                $packages[] = $package;
            }
        }
        if ($packages === []) {
            return [];
        }
        $in = implode(', ', array_fill(0, count($packages), '?'));
        $stmt = $container->get(Connection::class)->getPDO()
            ->prepare("SELECT slug FROM permissions WHERE managed_by IN ({$in})");
        $stmt->execute($packages);
        return array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
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
     * @param list<string> $withheld synced, but neither granted nor recorded as offered
     * @return array{0: int, 1: list<string>}
     */
    private function grantNew(string $roleSlug, array $except, ?array $offered, array $before, array $withheld): array
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
        // No record yet: a site not installed yet is a fresh install and is offered everything —
        // even though Aegis's own migration seeded the roles with its permissions, so they hold
        // grants already (reading that as an upgrade recorded every migration-seeded permission as
        // offered and granted none). An installed site's role holding grants has, in effect,
        // been offered whatever existed before this run.
        $offered ??= $held === [] || !$this->installed() ? [] : array_values(array_diff($before, $withheld));
        $offeredSet = array_fill_keys($offered, true);
        $withheldSet = array_fill_keys($withheld, true);

        $granted = 0;
        foreach ($permissions->findAllPermissions() as $permission) {
            $slug = $permission->getSlug();
            if (isset($offeredSet[$slug]) || isset($withheldSet[$slug]) || in_array($slug, $except, true)) {
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

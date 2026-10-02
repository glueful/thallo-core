<?php

declare(strict_types=1);

namespace Thallo\Core\Capabilities\Activation;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Starter\Kinds\BlockTypeKind;
use Thallo\Tenancy\System\SystemFlags;
use Thallo\Tenancy\Tenant\SingleStoreTenant;

/**
 * Seeds a capability's blocks (feature activation spec §3.4) from its EXPLICIT contributions —
 * never BlockTypeKind::definitions(), which leaves a gated contribution out while its capability is
 * off, as it is during preparation. Every insert goes through BlockInsert, so a block another
 * runner added is skipped without aborting the transaction.
 *
 * Workspace seeding keeps one lock order with finalization: withinWorkspaceSeed() takes the share
 * lock on every activation row first, before the starter kinds write anything, and reads each
 * capability's state fresh inside it — never from a registry decided when the request booted.
 */
final class CapabilityBlockSeeder
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly BlockTypeKind $kind,
        private readonly BlockTypeRepository $blocks,
        private readonly Connection $db,
        private readonly ActivationStore $store,
    ) {
    }

    /** @return list<string> slugs created in the current store */
    public function seedCurrent(string $capability): array
    {
        $created = [];
        foreach ($this->kind->contributionsFor($capability) as $definition) {
            $slug = $definition->definitionKey;
            if ($this->blocks->findBySlug($slug) !== null) {
                continue;
            }
            if (BlockInsert::ifAbsent($this->db, fn () => $this->blocks->create($definition->payload))) {
                $created[] = $slug;
            }
        }
        return $created;
    }

    /**
     * Every active workspace (workspaces on), or the current store. Each workspace's readiness is
     * recorded through $lease (fenced) when one is given; a workspace that no longer exists, or is no
     * longer active, is dropped. Without a lease, a failure is rethrown after the rest have been tried.
     *
     * @param list<string> $only Retry: only these workspaces
     * @return array<string, list<string>> workspace => created slugs, for the workspaces that succeeded
     */
    public function seedAll(string $capability, ?ActivationLease $lease = null, array $only = []): array
    {
        $created = [];
        $failure = null;
        $seedOne = function (string $workspace) use ($capability, $lease, &$created, &$failure): void {
            try {
                $created[$workspace] = $this->db->transaction(fn (): array => $this->seedCurrent($capability));
                if ($lease !== null) {
                    $this->store->markWorkspace($lease, $workspace, 'ready');
                }
            } catch (ActivationSuperseded $e) {
                throw $e;
            } catch (\Throwable $e) {
                $failure ??= $e;
                if ($lease !== null) {
                    $this->store->markWorkspace($lease, $workspace, 'failed');
                }
            }
        };

        $container = $this->context->getContainer();
        $flags = $container->has(SystemFlags::class) ? $container->get(SystemFlags::class) : null;
        if ($flags instanceof SystemFlags && $flags->tenancyEnabled() && $container->has(TenantContextRunner::class)) {
            $runner = $container->get(TenantContextRunner::class);
            if ($only === []) {
                $runner->forEachTenant(static fn (string $tenant) => $seedOne($tenant));
            } else {
                $admin = $container->get(TenantAdministration::class);
                foreach ($only as $tenant) {
                    // Gone, or no longer active (suspended): the seed reaches active workspaces only,
                    // so Retry drops it rather than failing on it until someone reactivates it.
                    $row = $admin->getTenant($this->context, $tenant);
                    if ($row === null || ($row['status'] ?? 'active') !== 'active') {
                        if ($lease !== null) {
                            $this->store->markWorkspace($lease, $tenant, null);
                        }
                        continue;
                    }
                    $runner->runAsTenant($tenant, static fn () => $seedOne($tenant));
                }
            }
        } else {
            $single = $container->has(SingleStoreTenant::class)
                ? $container->get(SingleStoreTenant::class)->defaultUuidOrNull()
                : null;
            $workspace = $single ?? 'single';
            if ($only === [] || in_array($workspace, $only, true)) {
                $seedOne($workspace);
            }
            foreach (array_diff($only, [$workspace]) as $gone) {
                if ($lease !== null) {
                    $this->store->markWorkspace($lease, $gone, null);
                }
            }
        }

        if ($lease === null && $failure !== null) {
            throw $failure;
        }
        return $created;
    }

    /**
     * A workspace's starter seed, in the one lock order: share-lock every activation row (fresh
     * state), run the starter kinds' writes, then seed every capability that is on or preparing.
     * Writes no activation row. Must run inside the workspace seed's transaction.
     */
    public function withinWorkspaceSeed(string $tenantUuid, callable $writeKinds): void
    {
        $states = $this->store->shareAll();
        $writeKinds();
        $this->pauseForTestsAfterKinds();
        foreach ($states as $capability => $state) {
            if ($state['on'] || $state['preparing']) {
                $this->seedCurrent($capability);
            }
        }
    }

    /** Test seam (APP_ENV=testing only): pause after the kinds have written, holding the locks. */
    private function pauseForTestsAfterKinds(): void
    {
        if (
            $this->context->getEnvironment() !== 'testing'
            || getenv('THALLO_TEST_PAUSE_IN_WORKSPACE_SEED') !== 'after-kinds'
        ) {
            return;
        }
        fwrite(STDOUT, "kinds-written\n");
        fflush(STDOUT);
        fgets(STDIN);
    }
}

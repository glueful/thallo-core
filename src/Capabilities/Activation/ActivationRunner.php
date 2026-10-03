<?php

declare(strict_types=1);

namespace Thallo\Core\Capabilities\Activation;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ExtensionManager;
use Psr\Container\ContainerInterface;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Core\Capabilities\CapabilityStateStore;
use Thallo\Core\Capabilities\FeatureManagementPolicy;
use Thallo\Core\Setup\InstallRoleGrants;

/**
 * Runs a capability's activation from its next step (feature activation spec §3.2–3.3), holding
 * the operation's lease; every record it writes is fenced on the generation and the lease.
 *
 *  - The engine step always ends the run: its provider boots only in a context booted after it,
 *    so the run returns needsBoot even when the engine was already prepared.
 *  - The fresh-boot gate runs only in such a context (the caller says so): the provider must be
 *    loaded in this boot and the capability's engine available, or the step fails with the cache
 *    rebuild as its remedy and the capability stays off.
 *  - Blocks are seeded into every workspace, then permissions granted inside the fence, so the
 *    operation's ownership is held through the grant transaction.
 *  - Finalization is one fenced transaction: the readiness check (missing contributions in every
 *    active workspace), then the switch on (which advances the capability state version), then
 *    the operation closed. Before that commit the capability is off; after it, it stays on.
 */
final class ActivationRunner
{
    /** @var ?\Closure(string): void test seam: 'before_commit' | 'after_commit' around finalization */
    public static ?\Closure $crashProbe = null;

    public function __construct(
        private readonly ActivationStore $store,
        private readonly CapabilityStateStore $states,
        private readonly FeatureManagementPolicy $policy,
        private readonly CapabilityBlockSeeder $seeder,
        private readonly InstallRoleGrants $grants,
        private readonly EngineActivation $engine,
        private readonly ContainerInterface $container,
    ) {
    }

    /**
     * Runs from the next step. Returns after the engine step (needs a boot), a failure, or the end.
     *
     * @param bool $freshBoot this context booted after the last engine step completed
     * @throws ActivationSuperseded when the generation is no longer current, or the lease was lost
     * @throws ActivationInProgress when another runner holds the lease
     */
    public function run(string $capability, int $generation, bool $freshBoot): ActivationOutcome
    {
        $engine = $this->policy->engineOf($capability)
            ?? throw new \InvalidArgumentException("{$capability} does not turn on through the activation flow.");
        $lease = $this->store->acquire($capability, $generation)
            ?? throw new ActivationInProgress("Activation {$capability} #{$generation} is already running.");

        try {
            $record = $this->mustFind($capability);
            while (($step = $record->nextStep()) !== null) {
                $this->pauseForTestsBefore($step);
                if ($step === ActivationStep::VERIFY_BOOT && !$freshBoot) {
                    return new ActivationOutcome($record, true);
                }
                try {
                    $record = match ($step) {
                        ActivationStep::ENABLE_ENGINE => $this->enableEngine($lease, $capability, $engine),
                        ActivationStep::VERIFY_BOOT => $this->verifyBoot($lease, $capability, $engine['provider']),
                        ActivationStep::SEED_BLOCKS => $this->seedBlocks($lease, $capability, $record),
                        ActivationStep::GRANT_PERMISSIONS => $this->grantPermissions($lease),
                        ActivationStep::FINALIZE => $this->finalize($lease, $capability),
                        default => $this->store->completeStep($lease, $step),
                    };
                } catch (ActivationSuperseded $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    return new ActivationOutcome($this->store->failStep($lease, $step, $e->getMessage(), null), false);
                }
                if ($record->status === ActivationStatus::FAILED) {
                    return new ActivationOutcome($record, false);
                }
                if ($step === ActivationStep::ENABLE_ENGINE) {
                    return new ActivationOutcome($record, true);
                }
                if ($step === ActivationStep::FINALIZE) {
                    // The commit is behind us: a crash or a lost response from here undoes nothing.
                    self::probe('after_commit');
                }
            }
            return new ActivationOutcome($record, false);
        } finally {
            $this->store->release($lease);
        }
    }

    /** @param array{package: string, provider: class-string} $engine */
    private function enableEngine(ActivationLease $lease, string $capability, array $engine): ActivationRecord
    {
        if ($this->engine->isPrepared($engine['package'], $engine['provider'])) {
            return $this->store->completeStep($lease, ActivationStep::ENABLE_ENGINE, ['engine' => 'already']);
        }
        if (!$this->engine->applicationFilesWritable()) {
            return $this->store->failStep(
                $lease,
                ActivationStep::ENABLE_ENGINE,
                "Application files can't be written on this host, so the engine ({$engine['package']}) "
                . "can't be enabled here. Prepare it at deploy time.",
                "php glueful thallo:capabilities:enable {$capability} --prepare",
            );
        }
        $prepared = $this->engine->prepare($engine['package'], $engine['provider'], 'activation:' . $capability);
        $patch = ['engine' => $prepared['status'] === 'already' ? 'already' : 'prepared'];
        $patch['cache_stale'] = $prepared['status'] === 'cache_stale';
        if ($prepared['error'] !== null) {
            $patch['cache_error'] = $prepared['error'];
        }
        return $this->store->completeStep($lease, ActivationStep::ENABLE_ENGINE, $patch);
    }

    private function verifyBoot(ActivationLease $lease, string $capability, string $provider): ActivationRecord
    {
        $reason = null;
        if (!$this->container->get(ExtensionManager::class)->hasProvider($provider)) {
            $reason = "The engine's provider ({$provider}) isn't loaded: the extension cache is out of date.";
        } else {
            $availability = $this->container->get(CapabilityRegistry::class)->availability($capability);
            if (!$availability->available) {
                $reason = (string) $availability->reason;
            }
        }
        if ($reason !== null) {
            return $this->store->failStep($lease, ActivationStep::VERIFY_BOOT, $reason, 'php glueful extensions:cache');
        }
        return $this->store->completeStep($lease, ActivationStep::VERIFY_BOOT, ['cache_stale' => false]);
    }

    private function seedBlocks(ActivationLease $lease, string $capability, ActivationRecord $record): ActivationRecord
    {
        $failedBefore = array_keys(array_filter($record->workspaces, static fn (string $s): bool => $s === 'failed'));
        $created = $this->seeder->seedAll($capability, $lease, $failedBefore);
        $total = (int) ($record->result['blocks_created'] ?? 0) + array_sum(array_map('count', $created));

        $failed = array_keys(array_filter(
            $this->mustFind($capability)->workspaces,
            static fn (string $s): bool => $s === 'failed',
        ));
        if ($failed !== []) {
            return $this->store->failStep(
                $lease,
                ActivationStep::SEED_BLOCKS,
                "The blocks couldn't be added in these workspaces: " . implode(', ', $failed) . '.',
                null,
                ['blocks_created' => $total],
            );
        }
        return $this->store->completeStep($lease, ActivationStep::SEED_BLOCKS, ['blocks_created' => $total]);
    }

    private function grantPermissions(ActivationLease $lease): ActivationRecord
    {
        $report = $this->store->withinFenced($lease, fn () => $this->grants->apply());
        return $this->store->completeStep($lease, ActivationStep::GRANT_PERMISSIONS, ['grants' => $report->granted]);
    }

    private function finalize(ActivationLease $lease, string $capability): ActivationRecord
    {
        return $this->store->withinFenced($lease, function () use ($lease, $capability): ActivationRecord {
            // The readiness check: workspaces created since the blocks were seeded get them now.
            $this->seeder->seedAll($capability);
            self::probe('before_commit');
            $this->states->put($capability, true);
            return $this->store->completeStep($lease, ActivationStep::FINALIZE);
        });
    }

    private static function probe(string $at): void
    {
        if (self::$crashProbe !== null) {
            (self::$crashProbe)($at);
        }
    }

    private function mustFind(string $capability): ActivationRecord
    {
        return $this->store->find($capability) ?? throw new \RuntimeException("No activation row for {$capability}.");
    }

    /** Test seam (APP_ENV=testing only): pause just before a step, holding the lease. */
    private function pauseForTestsBefore(string $step): void
    {
        $context = $this->container->get(ApplicationContext::class);
        if ($context->getEnvironment() !== 'testing' || getenv('THALLO_TEST_PAUSE_BEFORE_STEP') !== $step) {
            return;
        }
        fwrite(STDOUT, "paused\n");
        fflush(STDOUT);
        fgets(STDIN);
    }
}

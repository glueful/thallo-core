<?php

declare(strict_types=1);

namespace Thallo\Core\Capabilities\Activation;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\EnabledProviders;
use Glueful\Extensions\ExtensionManager;
use Glueful\Extensions\ExtensionStateWriter;
use Glueful\Extensions\Install\HostCapability;
use Glueful\Extensions\Schema\ExtensionOperation;
use Glueful\Extensions\Schema\ExtensionSchemaExecutor;
use Glueful\Extensions\Schema\ReadinessState;
use Glueful\Extensions\Schema\SchemaReadiness;
use Thallo\Contracts\Extensions\ExtensionStateCoordinator;

/**
 * Enables a feature's engine through Thallo's own path (feature activation spec §3.2, step 2):
 * the protected migration lane first, then, holding the extension-state lock, the enabled-list
 * write and the extension cache rebuild. This is the only step that writes application files.
 *
 * The constructor's last three arguments are test seams (production passes none): the enabled
 * list's path, whether application files are writable, and the cache rebuild.
 */
final class EngineActivation
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly ExtensionStateCoordinator $lock,
        private readonly ?string $configPath = null,
        private readonly ?\Closure $writable = null,
        private readonly ?\Closure $writeCache = null,
    ) {
    }

    /**
     * Migrates the engine, then writes it into the enabled list and rebuilds the cache, under the
     * lock. A failed rebuild leaves the engine listed and reports cache_stale; a failed migration
     * throws.
     *
     * @return array{status: 'prepared'|'cache_stale'|'already', error: ?string}
     */
    public function prepare(string $package, string $provider, string $actor): array
    {
        $operation = app($this->context, ExtensionSchemaExecutor::class)->migrateProtected($package, $actor);
        $migrated = [ExtensionOperation::STATUS_SUCCEEDED, ExtensionOperation::STATUS_CACHE_STALE];
        if (!in_array($operation->status, $migrated, true)) {
            throw new \RuntimeException(
                $operation->error ?? "{$package}'s migrations did not complete ({$operation->status})."
            );
        }

        return $this->lock->within(function () use ($provider): array {
            $already = $this->isListed($provider);
            if (!$already) {
                (new ExtensionStateWriter())->enable($this->enabledListPath(), $provider);
            }
            try {
                $this->rebuildCache();
            } catch (\Throwable $e) {
                return ['status' => 'cache_stale', 'error' => $e->getMessage()];
            }
            return ['status' => $already ? 'already' : 'prepared', 'error' => null];
        });
    }

    /**
     * Puts a provider back in the enabled list and rebuilds the cache, under the lock (provision's
     * repair of a required provider). False when it was already listed.
     */
    public function ensureListed(string $provider): bool
    {
        return $this->lock->within(function () use ($provider): bool {
            if ($this->isListed($provider)) {
                return false;
            }
            (new ExtensionStateWriter())->enable($this->enabledListPath(), $provider);
            $this->rebuildCache();
            return true;
        });
    }

    /** Listed in the enabled list and its schema ready: nothing for the engine step to do. */
    public function isPrepared(string $package, string $provider): bool
    {
        if (!$this->isListed($provider)) {
            return false;
        }
        foreach (app($this->context, SchemaReadiness::class)->forPackage($package) as $result) {
            if ($result['state'] !== ReadinessState::Ready) {
                return false;
            }
        }
        return true;
    }

    public function applicationFilesWritable(): bool
    {
        if ($this->writable !== null) {
            return ($this->writable)();
        }
        return app($this->context, HostCapability::class)->forToggle() === null;
    }

    /** In the enabled list as it is now (the seam's file in tests). */
    public function isListed(string $provider): bool
    {
        if ($this->configPath !== null) {
            $config = require $this->configPath;
            return in_array($provider, (array) ($config['enabled'] ?? []), true);
        }
        // Read the list as it is now (with its environment overlays), not as this context cached it.
        $this->context->clearConfigCache();
        return in_array($provider, EnabledProviders::from($this->context), true);
    }

    private function enabledListPath(): string
    {
        return $this->configPath ?? config_path($this->context, 'extensions.php');
    }

    private function rebuildCache(): void
    {
        if ($this->writeCache !== null) {
            ($this->writeCache)();
            return;
        }
        // The re-resolve must see the list just written, not the one cached before the write.
        $this->context->clearConfigCache();
        app($this->context, ExtensionManager::class)->writeCacheNow();
    }
}

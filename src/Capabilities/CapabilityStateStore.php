<?php

declare(strict_types=1);

namespace Thallo\Core\Capabilities;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Thallo\Contracts\Settings\SystemChannel;
use Thallo\Tenancy\System\SystemFlags;

/**
 * The ONE system-scoped capability switchboard (spec B3): requested state lives in the unscoped
 * system channel under `capability.<full-id>.enabled`, so a flip is a single row visible to
 * every tenant and every boot. Read order: canonical system key → the legacy `search_enabled`
 * system key (for `thallo.search` only) → the deploy-time `thallo.capabilities` config map.
 * explicit() reports null when none of those answers, so the registry can let an untouched
 * switch follow its engine; requested() keeps the historical default of true for callers that
 * need a plain bool. Reads fail SOFT to config before the system table exists (this runs during
 * every boot, including pre-provision CLI); writes fail EXPLICITLY — a switchboard write that
 * cannot persist must never report success, so every write reads itself back. The first
 * successful `thallo.search` write deletes the legacy key: one authority, not two.
 */
final class CapabilityStateStore
{
    public const PREFIX = 'capability.';
    private const SEARCH_ID = 'thallo.search';
    private const LEGACY_SEARCH_KEY = 'search_enabled';

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly SystemChannel $system,
    ) {
    }

    /** The existing read rules (canonical key → legacy search row → config map) over given rows. */
    /** The stored switch only, from snapshot rows: no legacy row, no configuration fallback. */
    public function storedFrom(array $rows, string $id): ?bool
    {
        $raw = $rows[self::PREFIX . $id . '.enabled'] ?? null;
        return $raw === null ? null : $this->decode((string) $raw);
    }

    public function explicitFrom(array $rows, string $id): ?bool
    {
        $raw = $rows[self::PREFIX . $id . '.enabled'] ?? null;
        if ($raw !== null) {
            return $this->decode((string) $raw);
        }
        if ($id === self::SEARCH_ID && isset($rows[self::LEGACY_SEARCH_KEY])) {
            return $this->decode((string) $rows[self::LEGACY_SEARCH_KEY]);
        }
        return $this->fromConfig($id);
    }

    /** A direct read, never memoised: what the switchboard says now, not when this request booted. */
    public function fresh(string $id): ?bool
    {
        if ($this->system instanceof SystemFlags) {
            $this->system->clearCache();
        }
        return $this->explicit($id);
    }

    /**
     * The stored switch alone, read now: no legacy row and no configuration fallback. An activation
     * capability is on only by this (spec §7.3a); null when nothing is stored or the table is absent.
     */
    public function storedFresh(string $id): ?bool
    {
        if ($this->system instanceof SystemFlags) {
            $this->system->clearCache();
        }
        try {
            $raw = $this->system->get(self::PREFIX . $id . '.enabled');
        } catch (\Throwable) {
            return null;
        }
        return $raw === null ? null : $this->decode($raw);
    }

    public function requested(string $id): bool
    {
        return $this->explicit($id) ?? true;
    }

    /** The explicit switchboard answer (stored row → legacy row → config map), or null if none. */
    public function explicit(string $id): ?bool
    {
        try {
            $raw = $this->system->get(self::PREFIX . $id . '.enabled');
            if ($raw !== null) {
                return $this->decode($raw);
            }
            if ($id === self::SEARCH_ID) {
                $legacy = $this->system->get(self::LEGACY_SEARCH_KEY);
                if ($legacy !== null) {
                    return $this->decode($legacy);
                }
            }
        } catch (\Throwable) {
            // Pre-provision boot (system table absent, DB unreachable): the config map stands.
        }
        return $this->fromConfig($id);
    }

    private function fromConfig(string $id): ?bool
    {
        $map = (array) config($this->context, 'thallo.capabilities', []);
        if (!array_key_exists($id, $map)) {
            return null;
        }
        return $map[$id] === true;
    }

    /**
     * Writes the switch and advances the capability-state version in one transaction (joining the
     * caller's, when there is one): a state change and the version that keys route tables never
     * diverge, and a crash before commit moves neither.
     */
    public function put(string $id, bool $enabled): void
    {
        $container = $this->context->getContainer();
        $db = $container->get(Connection::class);
        $version = $container->get(CapabilityStateVersion::class);
        $db->transaction(function () use ($id, $enabled, $version): void {
            $key = self::PREFIX . $id . '.enabled';
            $value = $enabled ? 'true' : 'false';
            $this->system->put($key, $value);
            if ($this->system->get($key) !== $value) {
                throw new \RuntimeException(
                    "Capability switchboard write for {$id} did not persist — refusing to report success."
                );
            }
            if ($id === self::SEARCH_ID) {
                // Cutover: the canonical key now answers, so the legacy row must stop existing.
                $this->system->forget(self::LEGACY_SEARCH_KEY);
            }
            $version->advance();
        });
    }

    private function decode(string $raw): bool
    {
        return in_array(strtolower($raw), ['1', 'true', 'on', 'yes'], true);
    }
}

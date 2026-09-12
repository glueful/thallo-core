<?php

declare(strict_types=1);

namespace Thallo\Core\Settings;

/**
 * Registry of the settings keys that belong to the unscoped system channel
 * ({@see \Thallo\Contracts\Settings\SystemChannel}) rather than the per-site `settings` table.
 *
 * These are install-and-runtime flags that must be readable before tenant resolution and must never
 * be tenant-scoped. Everything not listed here stays in the (soon tenant-scoped) `settings` table.
 */
final class SystemKeys
{
    /** @var list<string> */
    public const KEYS = [
        'installed',
        'scheduler_enabled',
        'webhooks_enabled',
        // The thallo.search capability switch: read by makeCapabilityRegistry() at BOOT
        // (before tenant resolution) and it gates instance-global route registration —
        // a tenant-scoped row would both throw under enforcement and fragment per
        // workspace what is physically one switch.
        'search_enabled',
        'admin_url',
    ];

    /**
     * Key prefixes whose ENTIRE namespace is system-scoped. `payvia.` covers every Payvia
     * gateway-credentials key (default_gateway, gateways.{id}.secret_key, …): platform
     * payments settings are shared across every tenant, so they must live in the unscoped
     * system channel rather than the (soon tenant-scoped) `settings` table.
     *
     * @var list<string>
     */
    public const PREFIXES = [
        // The update notice (UpdateChecker): the newest published version and when it was last
        // asked for are facts about the install, never about a workspace.
        'update.',
        // The scheduler heartbeat (SchedulerHeartbeat): when the tick last ran, install-wide.
        'scheduler.',
        'payvia.',
        // The capability switchboard (CapabilityStateStore): `capability.<full-id>.enabled`
        // rows are platform-wide requested state, never tenant-scoped.
        'capability.',
    ];

    public static function isSystem(string $key): bool
    {
        if (in_array($key, self::KEYS, true)) {
            return true;
        }

        foreach (self::PREFIXES as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }
}

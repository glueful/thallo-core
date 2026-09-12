<?php

declare(strict_types=1);

namespace Thallo\Core\Settings;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Payvia\Support\PayviaSettingsOverride;
use Thallo\Contracts\Settings\SystemChannel;

/**
 * Platform-payments-settings spec §2 (Task 4): the APP-OWNED implementation of payvia's host
 * settings seam, replacing the retired commerce-pack `SettingsStorePayviaOverride`.
 *
 * Gateway credentials are INSTALLATION-level infrastructure, not storefront content: the
 * `/webhooks/{gateway}` endpoint verifies signatures before any tenant context exists, and one
 * installation bills through one merchant account. Ownership therefore belongs to the app, over
 * the unscoped {@see SystemChannel}, and NOT to a capability-gated pack.
 *
 * Resolution order for every whitelisted key, verbatim from the spec:
 *  1. {@see PlatformPaymentSettingsStore} — the platform value (secrets decrypted on the way out).
 *  2. ONLY while the migration marker {@see MIGRATION_MARKER_KEY} is ABSENT from the
 *     {@see SystemChannel}: {@see LegacyPlatformPaymentSettingsReader}, the temporary read-only
 *     compatibility path over the OLD `settings` table. This is what keeps a not-yet-migrated
 *     deployment processing payments between deploy and migration.
 *  3. `null` — "no override", so payvia's own `config/payvia.php` + env fallback applies.
 *
 * Once the marker is present the legacy path is not consulted AT ALL, so a marked installation can
 * never regress to a legacy value even though the old rows are still physically present (pruning
 * them is a separate, explicit migration step). Writes never go anywhere near legacy storage —
 * this class does not write at all.
 *
 * The editable whitelist is ported VERBATIM from the retired override: `payvia.default_gateway`,
 * plus `payvia.gateways.{id}.{enabled|secret_key|webhook_secret}` for ids that exist in the
 * `payvia.gateways` CONFIG map — an override can reconfigure a configured gateway but never invent
 * one, and ops knobs (base URLs, timeouts, middleware) are not editable.
 *
 * TWO properties the retired implementation did not have:
 *  - ZERO capability gates. The old override consulted `CapabilityRegistry::isEnabled('thallo.commerce')`
 *    at value() time, which meant disabling the storefront capability silently reverted live
 *    gateway credentials to config/env. Nothing in this class is capability-aware.
 *  - No ambient tenant context anywhere. Neither source is selected through `SettingsStore`,
 *    `runAsTenant()`, or any current-tenant helper: the platform store reads the unscoped system
 *    channel, and the legacy reader resolves its candidate from the PERSISTED default-workspace
 *    pointer through direct queries. What a gateway or a signature check reads therefore cannot
 *    depend on which workspace happens to be current for the request doing the reading.
 *
 * Honors the seam's NULL-NEVER-THROW contract absolutely: an unknown key, an unbound/failing
 * store, an undecryptable row (rotated APP_KEY, tampered value), or ANY storage throwable all
 * resolve to null — config()/env stays the always-working fallback and a settings problem can
 * never break payment processing or webhook verification. A marker read that itself throws is
 * treated as "do not serve legacy": on an unknown marker state, falling back to config/env is the
 * conservative answer, never a legacy value.
 */
final class PlatformPayviaSettingsOverride implements PayviaSettingsOverride
{
    /**
     * The cutover marker, read DIRECTLY from the {@see SystemChannel} (never through
     * `SettingsStore`). Written — last, and only once every candidate key is accounted for — by
     * the migration command. Identical string in Tasks 4/5/8.
     */
    public const MIGRATION_MARKER_KEY = 'payments.platform_credentials_migrated';

    public function __construct(
        private readonly PlatformPaymentSettingsStore $platform,
        private readonly SystemChannel $system,
        private readonly LegacyPlatformPaymentSettingsReader $legacy,
    ) {
    }

    public function value(ApplicationContext $context, string $key): ?string
    {
        try {
            if ($this->whitelistedSubkey($context, $key) === null) {
                return null;
            }

            $platform = $this->platform->get($key);
            if (self::usable($platform)) {
                return $platform;
            }

            // Step 2 exists ONLY for the migration window. Any non-null marker value means this
            // installation has cut over: from here the legacy table is invisible, whatever is
            // still in it.
            if ($this->system->get(self::MIGRATION_MARKER_KEY) !== null) {
                return null;
            }

            $legacy = $this->legacy->value($key);

            return self::usable($legacy) ? $legacy : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Same emptiness rule as the retired override: a blank row is "no value", never an empty
     * string handed to payvia — so a blank platform row falls through to the legacy candidate
     * (while unmarked) and ultimately to config/env, exactly as before.
     */
    private static function usable(?string $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    /**
     * The whitelist check, ported VERBATIM from the retired
     * `Thallo\Commerce\Settings\SettingsStorePayviaOverride::whitelistedSubkey()`: returns the
     * terminal subkey ('default_gateway', 'enabled', 'secret_key', 'webhook_secret') when $key is
     * editable, null otherwise.
     *
     * Called from INSIDE value()'s try (the retired override called it outside): its `config()`
     * read is the one part of the whitelist that touches host state, and the null-never-throw
     * contract here is absolute. The whitelist LOGIC itself is unchanged.
     */
    private function whitelistedSubkey(ApplicationContext $context, string $key): ?string
    {
        if ($key === 'payvia.default_gateway') {
            return 'default_gateway';
        }

        if (preg_match('/^payvia\.gateways\.([a-z0-9_-]+)\.(enabled|secret_key|webhook_secret)$/', $key, $m) !== 1) {
            return null;
        }

        $configured = (array) config($context, 'payvia.gateways', []);

        return array_key_exists($m[1], $configured) ? $m[2] : null;
    }
}

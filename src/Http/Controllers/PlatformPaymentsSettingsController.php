<?php

declare(strict_types=1);

namespace Thallo\Core\Http\Controllers;

use Thallo\Core\Settings\PlatformPaymentSettingsStore;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Payvia\Support\PayviaSettingsOverride;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Delivery\CanonicalPublicOriginResolver;

/**
 * Platform-payments-settings spec §2 (Task 6): the neutral, app-owned Settings → Payments API —
 * `GET/PUT /v1/admin/settings/payments` — replacing thallo-commerce's retired
 * `Thallo\Commerce\Http\PaymentsSettingsController` (`/v1/admin/commerce/payments`).
 *
 * The response contract is preserved BYTE-SHAPE-IDENTICAL to the retiring controller: `mode`,
 * an ordered `gateways` list (`id`, `enabled{value,default,overridden}`,
 * `secret_key{set,source}`, `webhook_secret{set,source}`, `default`, `webhook_url`), and
 * `default_gateway{value,default,overridden}`. Only three things change: the URL (platform-owned,
 * not commerce-owned), the authority (`tenancy.manage`, not `commerce.manage` — see routes/admin.php),
 * and the storage owner (below).
 *
 * READS go through {@see PayviaSettingsOverride} — Task 4's app-owned host settings seam, bound to
 * {@see \Thallo\Core\Settings\PlatformPayviaSettingsOverride} — rather than
 * {@see PlatformPaymentSettingsStore} directly. During the migration window the override still
 * serves an UNMARKED legacy value when no platform row exists; GET must report exactly that same
 * effective source (platform row, else unmarked legacy, else config/env) so an operator never sees
 * a state runtime payment processing disagrees with. Once the migration marker is written, the
 * override stops consulting the legacy path and so does this controller, automatically.
 *
 * WRITES go through {@see PlatformPaymentSettingsStore} ONLY — never `SettingsStore`, never the
 * `settings` table. This closes the compatibility window that has been open since Task 4: before
 * this controller existed, a write through the retired commerce endpoint went through
 * thallo-commerce's `SettingsStore`-backed `CommerceSettingsStore`, which only the legacy
 * default-workspace leg of the override could ever see again.
 */
final class PlatformPaymentsSettingsController
{
    private const SECRET_MAX_LENGTH = 512;

    /** The only per-gateway fields a PUT may touch — anything else is an ops knob (base_url, …). */
    private const ALLOWED_GATEWAY_FIELDS = ['enabled', 'secret_key', 'webhook_secret'];

    /** Secret subkey names — mirrors {@see PlatformPaymentSettingsStore}'s own recognized set. */
    private const SECRET_FIELDS = ['secret_key', 'webhook_secret'];

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly PlatformPaymentSettingsStore $store,
        private readonly PayviaSettingsOverride $override,
        private readonly CanonicalPublicOriginResolver $origins,
    ) {
    }

    /** GET /v1/admin/settings/payments */
    #[ApiOperation(
        summary: 'Get platform payment gateway settings (booleans for secrets — key material never returned)',
        description: 'Effective platform payment gateway configuration during the platform-payments-settings '
            . 'migration cutover: a stored platform value, else (only before the migration marker is written) an '
            . 'unmarked legacy value, else the config/.env default. Secret fields are reported as booleans only. '
            . 'Requires `tenancy.manage`.',
        tags: ['Thallo Settings'],
    )]
    public function show(Request $request): Response
    {
        return Response::success($this->state(), 'Payment settings retrieved');
    }

    /** PUT /v1/admin/settings/payments */
    #[ApiOperation(
        summary: 'Update platform payment gateway settings (secrets are write-only: blank clears, absent keeps)',
        description: 'Field ABSENT leaves the stored value unchanged; `null` or a blank string clears it '
            . '(forget — the config/env fallback then shows through); a non-blank string is validated and stored '
            . '(secrets encrypted at rest). Every write lands in the unscoped platform system channel via '
            . 'PlatformPaymentSettingsStore only. Requires `tenancy.manage`.',
        tags: ['Thallo Settings'],
    )]
    public function update(Request $request): Response
    {
        $configured = $this->configuredGateways();
        if ($configured === []) {
            throw ValidationException::forField(
                'payments',
                'No payment gateway extension is installed — there is nothing to configure.',
            );
        }

        $body = (array) json_decode((string) $request->getContent(), true);

        $puts = [];
        $forgets = [];

        if (array_key_exists('default_gateway', $body)) {
            $raw = $body['default_gateway'];
            if ($raw === null || (is_string($raw) && trim($raw) === '')) {
                $forgets[] = 'payvia.default_gateway';
            } elseif (!is_string($raw) || !array_key_exists(trim($raw), $configured)) {
                throw ValidationException::forField('default_gateway', 'Unknown gateway.');
            } else {
                $puts['payvia.default_gateway'] = trim($raw);
            }
        }

        $gateways = $body['gateways'] ?? [];
        if (!is_array($gateways)) {
            throw ValidationException::forField('gateways', 'Must be an object keyed by gateway id.');
        }
        foreach ($gateways as $id => $fields) {
            if (!is_string($id) || !array_key_exists($id, $configured)) {
                throw ValidationException::forField("gateways.{$id}", 'Unknown gateway.');
            }
            if (!is_array($fields)) {
                throw ValidationException::forField("gateways.{$id}", 'Must be an object of fields.');
            }

            foreach (array_keys($fields) as $field) {
                if (!is_string($field) || !in_array($field, self::ALLOWED_GATEWAY_FIELDS, true)) {
                    throw ValidationException::forField(
                        "gateways.{$id}.{$field}",
                        'Unknown field — ops knobs (base URLs, timeouts, driver, …) are config/env-only.',
                    );
                }
            }

            if (array_key_exists('enabled', $fields)) {
                if (!is_bool($fields['enabled'])) {
                    throw ValidationException::forField("gateways.{$id}.enabled", 'Must be true or false.');
                }
                $puts["payvia.gateways.{$id}.enabled"] = $fields['enabled'] ? '1' : '0';
            }

            foreach (self::SECRET_FIELDS as $secret) {
                if (!array_key_exists($secret, $fields)) {
                    continue; // write-only: absent = untouched
                }
                $key = "payvia.gateways.{$id}.{$secret}";
                $raw = $fields[$secret];
                if ($raw === null || (is_string($raw) && trim($raw) === '')) {
                    $forgets[] = $key;
                    continue;
                }
                if (!is_string($raw)) {
                    throw ValidationException::forField("gateways.{$id}.{$secret}", 'Must be a string.');
                }
                $value = trim($raw);
                if (mb_strlen($value) > self::SECRET_MAX_LENGTH) {
                    throw ValidationException::forField(
                        "gateways.{$id}.{$secret}",
                        'Must be ' . self::SECRET_MAX_LENGTH . ' characters or fewer.',
                    );
                }
                // PlatformPaymentSettingsStore encrypts secret subkeys itself (AAD = the full
                // key) — plaintext is handed to putMany(), never encrypted here.
                $puts[$key] = $value;
            }
        }

        foreach ($forgets as $key) {
            $this->store->forget($key);
        }
        if ($puts !== []) {
            $this->store->putMany($puts);
        }

        return Response::success($this->state(), 'Payment settings saved');
    }

    /** @return array<string,mixed> */
    private function state(): array
    {
        $configured = $this->configuredGateways();
        if ($configured === []) {
            return [
                'mode' => 'manual',
                'default_gateway' => null,
                'gateways' => [],
            ];
        }

        $default = $this->effectiveDefaultGateway();
        $rows = [];
        foreach ($configured as $id => $config) {
            $rows[] = [
                'id' => $id,
                'enabled' => [
                    'value' => $this->effectiveEnabled($id, $config),
                    'default' => (bool) ($config['enabled'] ?? false),
                    'overridden' => $this->overriddenValue("payvia.gateways.{$id}.enabled") !== null,
                ],
                'secret_key' => $this->secretState($id, $config, 'secret_key'),
                'webhook_secret' => $this->secretState($id, $config, 'webhook_secret'),
                'default' => $id === $default,
                'webhook_url' => $this->webhookUrl($id),
            ];
        }

        return [
            'mode' => 'gateway',
            'default_gateway' => [
                'value' => $default,
                'default' => (string) config($this->context, 'payvia.default_gateway', ''),
                'overridden' => $this->overriddenValue('payvia.default_gateway') !== null,
            ],
            'gateways' => $rows,
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function configuredGateways(): array
    {
        $out = [];
        foreach ((array) config($this->context, 'payvia.gateways', []) as $id => $config) {
            if (is_array($config)) {
                $out[(string) $id] = $config;
            }
        }

        return $out;
    }

    private function effectiveDefaultGateway(): ?string
    {
        $override = $this->overriddenValue('payvia.default_gateway');
        if ($override !== null && preg_match('/^[a-z0-9_-]+$/', $override) === 1) {
            return $override;
        }
        $config = (string) config($this->context, 'payvia.default_gateway', '');

        return $config !== '' ? $config : null;
    }

    /** @param array<string,mixed> $config */
    private function effectiveEnabled(string $id, array $config): bool
    {
        $override = $this->overriddenValue("payvia.gateways.{$id}.enabled");
        if ($override !== null && in_array(strtolower($override), ['1', 'true', '0', 'false'], true)) {
            return in_array(strtolower($override), ['1', 'true'], true);
        }

        return (bool) ($config['enabled'] ?? false);
    }

    /**
     * A secret field's reportable state — booleans only: is an effective value present, and does
     * it come from a stored settings row (platform, or — pre-marker — legacy) or from config/env?
     * Mirrors {@see PayviaSettingsOverride}'s own resolution order exactly, never
     * {@see PlatformPaymentSettingsStore} alone.
     *
     * @param array<string,mixed> $config
     * @return array{set: bool, source: ?string}
     */
    private function secretState(string $id, array $config, string $field): array
    {
        if ($this->overriddenValue("payvia.gateways.{$id}.{$field}") !== null) {
            return ['set' => true, 'source' => 'settings'];
        }

        $env = $config[$field] ?? null;
        if (is_string($env) && trim($env) !== '') {
            return ['set' => true, 'source' => 'env'];
        }

        return ['set' => false, 'source' => null];
    }

    /**
     * The absolute URL the merchant pastes into the gateway dashboard. Payvia's webhook route is
     * root-mounted (`POST /webhooks/{gateway}`); the origin half comes from the ONE trusted origin
     * authority ({@see CanonicalPublicOriginResolver} — never the request Host header).
     */
    private function webhookUrl(string $id): ?string
    {
        try {
            return $this->origins->currentOrigin($this->context) . '/webhooks/' . rawurlencode($id);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The effective value THROUGH THE OVERRIDE SEAM — a platform row, else (only pre-marker) an
     * unmarked legacy row, else null — never {@see PlatformPaymentSettingsStore} read directly.
     * Blank is treated as "no value", matching the seam's own emptiness rule.
     */
    private function overriddenValue(string $key): ?string
    {
        $value = $this->override->value($this->context, $key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}

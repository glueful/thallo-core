<?php

declare(strict_types=1);

namespace Thallo\Core\Settings;

use Glueful\Encryption\EncryptionService;
use Thallo\Contracts\Settings\SystemChannel;

/**
 * Platform-payments-settings spec (Task 2): the app-owned write/read surface over the unscoped
 * {@see SystemChannel} for Payvia gateway credentials — the `payvia.`-prefixed keys Task 1 made
 * system keys (see {@see SystemKeys::PREFIXES}). Once a later task routes the payments settings
 * path through it, this becomes the place that encrypts/decrypts those rows on this store's
 * behalf; callers get and set plain strings and never touch {@see EncryptionService} themselves.
 * Task 4 has since retired that legacy class in favour of {@see PlatformPayviaSettingsOverride},
 * which reads THROUGH this store; the AAD/secret-subkey conventions below are what made that
 * cutover a pure rebinding rather than a re-encryption.
 *
 * SECRET subkeys (`secret_key`, `webhook_secret` — the terminal dot-segment of the key, e.g.
 * `payvia.gateways.stripe.secret_key`) are encrypted at rest with AAD = the full settings key
 * string. This class recognizes secrets by the SAME subkey-NAME set as the retired
 * `Thallo\Commerce\Settings\SettingsStorePayviaOverride` used, with the identical AAD
 * convention, so ciphertext produced by that legacy write path still decrypts here. It does NOT
 * reproduce that override's namespace/config whitelist (`payvia.gateways.{id}.…` for an `$id`
 * present in the `payvia.gateways` config) — this is a generic key/value store and that
 * structural gate is deliberately left to the override (Task 4) and the settings controller
 * (Task 6): this class alone would encrypt/decrypt a `secret_key`/`webhook_secret` subkey under
 * any namespace, not only `payvia.gateways.*`. Non-secret subkeys (`default_gateway`, `enabled`,
 * …) are stored as plain strings.
 *
 * Reads are null-never-throw: an undecryptable/tampered row or ANY {@see SystemChannel} throwable
 * resolves to null — a settings problem can never surface as an exception to a caller that just
 * wants "is there a value for this key".
 *
 * `importEncryptedForMigration()` is the deliberately narrow migration door (cutover from the
 * legacy commerce-settings path): it accepts ONLY a recognized secret key, requires the given
 * bytes already look like a ciphertext, PROVES they decrypt under this key's AAD, and only then
 * writes those exact bytes verbatim — never re-encrypting. Invalid input throws before anything
 * is written.
 */
final class PlatformPaymentSettingsStore
{
    private const SECRET_SUBKEYS = ['secret_key', 'webhook_secret'];

    public function __construct(
        private readonly SystemChannel $system,
        private readonly EncryptionService $encryption,
    ) {
    }

    /**
     * The stored value for a key, decrypted when it's a secret subkey — or null when no row
     * exists, the row won't decrypt (tampered value, rotated key), or the channel throws.
     */
    public function get(string $key): ?string
    {
        try {
            $stored = $this->system->get($key);
            if ($stored === null) {
                return null;
            }

            if (!$this->isSecretKey($key)) {
                return $stored;
            }

            if (!$this->encryption->isEncrypted($stored)) {
                return null;
            }

            return $this->encryption->decrypt($stored, aad: $key);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Upsert each pair through the system channel. Secret subkeys are encrypted (AAD = the full
     * key string) before the write; every other key is written as-is.
     *
     * @param array<string,string> $pairs
     */
    public function putMany(array $pairs): void
    {
        foreach ($pairs as $key => $value) {
            $toStore = $this->isSecretKey($key)
                ? $this->encryption->encrypt($value, aad: $key)
                : $value;
            $this->system->put($key, $toStore);
        }
    }

    public function forget(string $key): void
    {
        $this->system->forget($key);
    }

    /**
     * Migration-only door: import a ciphertext produced ELSEWHERE (the legacy commerce-settings
     * path) verbatim, without re-encrypting it. Validates first, writes second — never the
     * reverse:
     *  1. $key must be a recognized secret subkey (otherwise there is nothing to decrypt/verify).
     *  2. $ciphertext must already look like an encrypted payload.
     *  3. $ciphertext must actually decrypt under $key's AAD — proving it is a real, intact
     *     ciphertext for THIS key, not garbage or one bound to a different key.
     * Only once all three hold does the exact ciphertext byte string get written.
     */
    public function importEncryptedForMigration(string $key, string $ciphertext): void
    {
        if (!$this->isSecretKey($key)) {
            throw new \InvalidArgumentException(
                "Cannot import a ciphertext for [{$key}]: not a recognized secret key."
            );
        }

        if (!$this->encryption->isEncrypted($ciphertext)) {
            throw new \InvalidArgumentException(
                "Cannot import for [{$key}]: value is not a recognized ciphertext."
            );
        }

        // Proof of validity — decrypt() throws (DecryptionException/KeyNotFoundException) when
        // the payload is malformed, tampered, or bound to a different key's AAD. Nothing is
        // written unless this line returns normally, and the decrypted plaintext itself is
        // discarded — only the original ciphertext bytes are ever persisted.
        $this->encryption->decrypt($ciphertext, aad: $key);

        $this->system->put($key, $ciphertext);
    }

    /**
     * Same secret SUBKEY-NAME set the retired
     * `Thallo\Commerce\Settings\SettingsStorePayviaOverride::whitelistedSubkey()` used: the
     * terminal dot-segment of the key must be one of the SECRET_SUBKEYS. Unlike that override
     * (and unlike {@see PlatformPayviaSettingsOverride}), this check is NOT namespace/config-gated
     * — it does not verify the key sits under
     * `payvia.gateways.{configured-id}.…`. That structural whitelist is enforced by the caller
     * layers ({@see PlatformPayviaSettingsOverride}, the settings controller), not here.
     */
    private function isSecretKey(string $key): bool
    {
        $lastDot = strrpos($key, '.');
        $subkey = $lastDot === false ? $key : substr($key, $lastDot + 1);

        return in_array($subkey, self::SECRET_SUBKEYS, true);
    }
}

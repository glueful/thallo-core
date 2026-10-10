<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Preview;

/**
 * An opaque, HMAC-signed capability bound to exactly one {entry, locale, ?version}
 * with an expiry. The token IS the preview capability: anyone holding a valid,
 * unexpired, correctly-signed token may read that one draft and nothing else.
 *
 * Wire format: base64url(json{e,l,v,exp}) . base64url(hmac_sha256(payloadPart, key)).
 *
 * Security invariants:
 *  - Fail closed: any problem -> PreviewTokenException, never a partial/forged read.
 *  - The signature is verified (constant-time hash_equals) BEFORE the payload is
 *    decoded or trusted. A tampered payload is rejected at the signature step.
 *  - Binding: the signature covers the encoded {entry, locale, version, exp}, so a
 *    token minted for entry A cannot be re-pointed at entry B without invalidation.
 */
final class PreviewToken
{
    private function __construct(
        public readonly string $entryUuid,
        public readonly string $locale,
        public readonly ?string $versionUuid,
        public readonly int $expiresAt,
        public readonly ?string $theme = null,
        public readonly ?string $accent = null,
        public readonly ?string $neutral = null,
        /**
         * Pending design settings (radius, font, background), any subset; null = none. Like the
         * colours, previewed from the token and never written anywhere.
         *
         * @var array<string,string>|null
         */
        public readonly ?array $design = null,
        /**
         * A pending palette (custom palette spec §5.1): `neutral_custom`, `dark_base`, `brands`, any
         * subset; null = none. Previewed from the token and never written anywhere.
         *
         * @var array<string,mixed>|null
         */
        public readonly ?array $palette = null,
    ) {
    }

    /** For ALREADY-VERIFIED claims only (PreviewReader::readVerified) — never wire input. */
    public static function fromVerifiedClaims(
        string $entryUuid,
        string $locale,
        ?string $versionUuid,
        int $expiresAt,
        ?string $theme = null,
    ): self {
        return new self($entryUuid, $locale, $versionUuid, $expiresAt, $theme);
    }

    public static function mint(
        string $entryUuid,
        string $locale,
        ?string $versionUuid,
        int $expiresAt,
        string $key,
        ?string $theme = null,
        ?string $accent = null,
        ?string $neutral = null,
        ?array $design = null,
        ?array $palette = null,
    ): string {
        $claims = [
            'e' => $entryUuid,
            'l' => $locale,
            'v' => $versionUuid,
            'exp' => $expiresAt,
            // Additive claims (preview-sessions spec §5, theme-color-config spec §6):
            // absent on old tokens, which keep verifying — forward-compatible payload.
            't' => $theme,
            'a' => $accent,
            'n' => $neutral,
        ];
        // The design claim is WRITTEN only when there is one: a token without pending design
        // settings is byte-for-byte what it always was.
        if ($design !== null && $design !== []) {
            $claims['d'] = $design;
        }
        // The palette claim the same way (custom palette spec §5.1): absent unless there is one.
        if ($palette !== null && $palette !== []) {
            $claims['p'] = $palette;
        }
        $payload = self::b64(json_encode($claims, JSON_THROW_ON_ERROR));

        $sig = self::b64(hash_hmac('sha256', $payload, $key, true));

        return $payload . '.' . $sig;
    }

    public static function verify(string $token, string $key, int $now): self
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            throw PreviewTokenException::malformed();
        }
        [$payload, $sig] = $parts;

        // Constant-time signature check FIRST — never trust an unverified payload.
        $expected = self::b64(hash_hmac('sha256', $payload, $key, true));
        if (!hash_equals($expected, $sig)) {
            throw PreviewTokenException::invalidSignature();
        }

        // Signature is valid: now it is safe to decode and trust the payload.
        $data = json_decode(self::unb64($payload), true);
        if (!is_array($data) || !isset($data['e'], $data['l'], $data['exp'])) {
            throw PreviewTokenException::malformed();
        }

        if ((int) $data['exp'] < $now) {
            throw PreviewTokenException::expired();
        }

        return new self(
            (string) $data['e'],
            (string) $data['l'],
            isset($data['v']) && is_string($data['v']) ? $data['v'] : null,
            (int) $data['exp'],
            isset($data['t']) && is_string($data['t']) ? $data['t'] : null,
            isset($data['a']) && is_string($data['a']) ? $data['a'] : null,
            isset($data['n']) && is_string($data['n']) ? $data['n'] : null,
            self::designClaim($data['d'] ?? null),
            self::paletteClaim($data['p'] ?? null),
        );
    }

    /**
     * The palette claim, read defensively: exactly the keys `neutral_custom` (null or the six neutral
     * names to strings), `dark_base` (null or a string) and `brands` (a list of {id, name, hex} in
     * display order — the whole pending list — with distinct ids from 1 to 9999); anything else is no
     * palette. The minter validated the colours.
     *
     * @return array<string,mixed>|null
     */
    private static function paletteClaim(mixed $claim): ?array
    {
        if (!is_array($claim) || $claim === []) {
            return null;
        }
        foreach ($claim as $key => $value) {
            $ok = match ($key) {
                'neutral_custom' => $value === null || (is_array($value) && self::stringMap($value, 6)),
                'dark_base' => $value === null || is_string($value),
                'brands' => is_array($value) && self::brandsClaim($value),
                default => false,
            };
            if (!$ok) {
                return null;
            }
        }
        return $claim;
    }

    /** @param array<mixed> $map */
    private static function stringMap(array $map, int $size): bool
    {
        if (count($map) !== $size) {
            return false;
        }
        foreach ($map as $k => $v) {
            if (!is_string($k) || !is_string($v)) {
                return false;
            }
        }
        return true;
    }

    /** @param array<mixed> $brands */
    private static function brandsClaim(array $brands): bool
    {
        if (!array_is_list($brands)) {
            return false;
        }
        $ids = [];
        foreach ($brands as $brand) {
            $id = is_array($brand) ? \Thallo\Core\Settings\BrandColors::id($brand['id'] ?? null) : null;
            $named = is_array($brand) && is_string($brand['name'] ?? null) && is_string($brand['hex'] ?? null);
            if ($id === null || !$named || isset($ids[$id])) {
                return false;
            }
            $ids[$id] = true;
        }
        return true;
    }

    /**
     * A claim is read defensively even though it is signed: anything that is not a non-empty map
     * of name => string is no design at all.
     *
     * @return array<string,string>|null
     */
    private static function designClaim(mixed $claim): ?array
    {
        if (!is_array($claim) || $claim === []) {
            return null;
        }
        foreach ($claim as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                return null;
            }
        }
        return $claim;
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/'));
    }
}

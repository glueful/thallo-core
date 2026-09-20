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
        );
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

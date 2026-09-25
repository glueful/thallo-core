<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Preview;

/**
 * A regions-stage session token (regions-stage spec §4.1): `{k: "regions", s, p, l, exp}` signed
 * like {@see PreviewToken}. Its own claims and its own parser: `PreviewToken::verify` refuses it
 * (no entry claim) and this refuses an entry token (no kind), so neither passes for the other.
 */
final class RegionPreviewToken
{
    public const KIND = 'regions';

    private function __construct(
        public readonly string $session,
        public readonly ?string $page,
        public readonly string $locale,
        public readonly int $expiresAt,
    ) {
    }

    public static function mint(string $session, ?string $page, string $locale, int $expiresAt, string $key): string
    {
        $payload = self::b64(json_encode([
            'k' => self::KIND,
            's' => $session,
            'p' => $page,
            'l' => $locale,
            'exp' => $expiresAt,
        ], JSON_THROW_ON_ERROR));
        return $payload . '.' . self::b64(hash_hmac('sha256', $payload, $key, true));
    }

    public static function verify(string $token, string $key, int $now): self
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            throw PreviewTokenException::malformed();
        }
        [$payload, $sig] = $parts;
        if (!hash_equals(self::b64(hash_hmac('sha256', $payload, $key, true)), $sig)) {
            throw PreviewTokenException::invalidSignature();
        }
        $data = json_decode(self::unb64($payload), true);
        if (
            !is_array($data)
            || ($data['k'] ?? null) !== self::KIND
            || !is_string($data['s'] ?? null) || $data['s'] === ''
            || !is_string($data['l'] ?? null)
            || !isset($data['exp'])
        ) {
            throw PreviewTokenException::malformed();
        }
        if ((int) $data['exp'] < $now) {
            throw PreviewTokenException::expired();
        }
        return new self(
            $data['s'],
            is_string($data['p'] ?? null) && $data['p'] !== '' ? $data['p'] : null,
            $data['l'],
            (int) $data['exp'],
        );
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

<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Preview;

/**
 * A layout editing session's token (type layouts spec §5.2): `{k: "layout", s, u, t, x, l, exp}` —
 * the session id, the surface, the target, the sample (null for the placeholder), the locale —
 * signed like {@see PreviewToken}. Its own parser: an entry token and a regions token fail it, and
 * it fails theirs.
 */
final class LayoutPreviewToken
{
    public const KIND = 'layout';

    private function __construct(
        public readonly string $session,
        public readonly string $surface,
        public readonly string $target,
        public readonly ?string $sample,
        public readonly string $locale,
        public readonly int $expiresAt,
    ) {
    }

    public static function mint(
        string $session,
        string $surface,
        string $target,
        ?string $sample,
        string $locale,
        int $expiresAt,
        string $key,
    ): string {
        $payload = self::b64(json_encode([
            'k' => self::KIND,
            's' => $session,
            'u' => $surface,
            't' => $target,
            'x' => $sample,
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
            || !is_string($data['u'] ?? null) || $data['u'] === ''
            || !is_string($data['t'] ?? null) || $data['t'] === ''
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
            $data['u'],
            $data['t'],
            is_string($data['x'] ?? null) && $data['x'] !== '' ? $data['x'] : null,
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

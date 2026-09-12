<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Delivery;

/**
 * Opaque, stateless pagination cursor.
 *
 * A cursor carries the last row's keyset position — the value of the active sort
 * expression plus the monotonic `v.id` tiebreaker — base64url-encoded. Because it
 * keys on `v.id` (a surrogate autoincrement that never moves) rather than an offset,
 * paging is stable under publish churn: rows inserted/removed between page requests
 * cannot cause skips or duplicates.
 *
 * The token is NOT signed — it is opaque, not secret. A tampered or garbage token
 * decodes to `null` and is simply ignored (treated as "no cursor"), never an error,
 * so a hostile cursor degrades to "first page" instead of leaking or crashing.
 *
 * Shape of a decoded key: `['sort' => scalar|null, 'id' => int]`.
 */
final class Cursor
{
    /**
     * @param array{sort:scalar|null,id:int} $key
     */
    public static function encode(array $key): string
    {
        $json = json_encode(['sort' => $key['sort'] ?? null, 'id' => $key['id']]);
        if ($json === false) {
            return '';
        }
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @return array{sort:scalar|null,id:int}|null  null when the token is missing,
     *         malformed, tampered, or structurally invalid (ignored, never fatal)
     */
    public static function decode(string $token): ?array
    {
        if ($token === '') {
            return null;
        }
        // base64url is [A-Za-z0-9_-]; anything else is tampered/garbage.
        if (preg_match('/\A[A-Za-z0-9_-]+\z/', $token) !== 1) {
            return null;
        }
        $padded = strtr($token, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        $json = base64_decode($padded, true);
        if ($json === false) {
            return null;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !array_key_exists('sort', $decoded) || !array_key_exists('id', $decoded)) {
            return null;
        }
        $id = $decoded['id'];
        if (!is_int($id)) {
            return null;
        }
        $sort = $decoded['sort'];
        if ($sort !== null && !is_scalar($sort)) {
            return null;
        }
        return ['sort' => $sort, 'id' => $id];
    }
}

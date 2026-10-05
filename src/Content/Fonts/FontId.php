<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Fonts;

/**
 * A stored typeface (block typeface spec §1): a reserved built-in, or an uploaded family's stable
 * 12-character ID. `inherit` and `reset` are never IDs — reset is a value kind of its own.
 */
final class FontId
{
    public const RESERVED = ['theme', 'serif', 'humanist', 'geometric', 'slab', 'mono', 'system'];

    public static function isValid(string $id): bool
    {
        return self::isReserved($id) || preg_match('/\A[A-Za-z0-9]{12}\z/', $id) === 1;
    }

    public static function isReserved(string $id): bool
    {
        return in_array($id, self::RESERVED, true);
    }
}

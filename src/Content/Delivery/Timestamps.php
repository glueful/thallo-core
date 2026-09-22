<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Delivery;

/**
 * Timestamp normalization for the engine's content readers: the `lastmod` that search and the
 * sitemap receive. The delivery API's `published_at` does not pass through here yet and is
 * emitted as the database wrote it.
 */
final class Timestamps
{
    /** A DB timestamp value as ISO-8601 (`date('c')`), or null when absent/unparseable. */
    public static function iso(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $ts = strtotime($value);
        return $ts === false ? null : date('c', $ts);
    }
}

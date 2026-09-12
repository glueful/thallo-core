<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Delivery;

/**
 * Timestamp normalization shared by the delivery readers, so the delivery API's
 * `published_at` and search's `lastmod` always format DB timestamps identically.
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

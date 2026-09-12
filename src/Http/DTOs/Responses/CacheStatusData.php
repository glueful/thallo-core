<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs\Responses;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only cache status ({@see \Thallo\Core\Http\Controllers\CacheAdminController::show()}).
 */
final class CacheStatusData implements ResponseData
{
    /** @param list<CacheStatItemData> $stats */
    public function __construct(
        public readonly string $driver,
        public readonly string $prefix,
        public readonly bool $tags_enabled,
        public readonly int $key_count,
        public readonly array $stats,
    ) {
    }
}

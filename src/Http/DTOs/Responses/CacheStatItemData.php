<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs\Responses;

use Glueful\Http\Contracts\ResponseData;

/** Doc-only key/value stat row from the cache driver ({@see \Thallo\Core\Http\Controllers\CacheAdminController}). */
final class CacheStatItemData implements ResponseData
{
    public function __construct(
        public readonly string $key,
        public readonly string $value,
    ) {
    }
}

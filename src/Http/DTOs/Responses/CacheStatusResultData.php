<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs\Responses;

use Glueful\Http\Contracts\ResponseData;

/** Doc-only envelope for the cache status/clear responses. */
final class CacheStatusResultData implements ResponseData
{
    public function __construct(
        public readonly CacheStatusData $cache,
    ) {
    }
}

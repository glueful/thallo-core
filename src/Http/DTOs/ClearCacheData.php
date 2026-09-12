<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Request body for `POST /v1/admin/cache/clear`
 * ({@see \Thallo\Core\Http\Controllers\CacheAdminController::clear()}).
 *
 * `content_type`: when supplied, only that content type's delivery cache is invalidated
 * (the `thallo:type:<slug>` tag); when omitted, the whole cache is flushed.
 */
final class ClearCacheData implements RequestData
{
    public function __construct(
        #[Rule('string')]
        public readonly ?string $content_type = null,
    ) {
    }
}

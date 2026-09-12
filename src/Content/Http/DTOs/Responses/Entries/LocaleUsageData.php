<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs\Responses\Entries;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only schema holder: the success-envelope `data` payload returned by
 * {@see \Thallo\Core\Content\Http\Controllers\LocaleAdminController::usage()}.
 * NEVER constructed at runtime — it exists only so the OpenAPI generator can reflect
 * a typed schema for the published/draft entry counts for a locale.
 */
final class LocaleUsageData implements ResponseData
{
    public function __construct(
        public readonly int $published_entries,
        public readonly int $draft_entries,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs\Responses\Entries;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only schema holder: the success-envelope `data` payload returned by
 * {@see \Thallo\Core\Content\Http\Controllers\EntryController::show()} (HTTP 200).
 * NEVER constructed at runtime — it exists only so the OpenAPI generator can reflect
 * a typed schema for the `{entry}` wrapper returned when reading an entry record.
 */
final class EntryResultData implements ResponseData
{
    public function __construct(
        public readonly EntryData $entry,
    ) {
    }
}

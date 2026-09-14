<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs\Responses\Entries;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only schema holder: the success-envelope `data` payload returned by
 * {@see \Thallo\Core\Content\Http\Controllers\EntryController::getDraft()} and
 * {@see \Thallo\Core\Content\Http\Controllers\EntryController::saveDraft()} (both HTTP 200).
 * NEVER constructed at runtime — it exists only so the OpenAPI generator can reflect
 * a typed schema for the `{draft}` wrapper returned when reading or saving a draft.
 */
final class DraftResultData implements ResponseData
{
    public function __construct(
        public readonly DraftData $draft,
    ) {
    }
}

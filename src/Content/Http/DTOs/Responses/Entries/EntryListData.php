<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs\Responses\Entries;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only shape of the admin entry-list envelope (see EntryController::index).
 */
final class EntryListData implements ResponseData
{
    /** @param list<EntryListItemData> $entries */
    public function __construct(
        public readonly array $entries,
        public readonly int $total,
        public readonly int $current_page,
        public readonly int $per_page,
    ) {
    }
}

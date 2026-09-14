<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs\Responses\Entries;

use Glueful\Http\Contracts\ResponseData;

/** The saved draft, and whether the save cleared the preview working copy (visual builder spec §3.5). */
final class DraftSaveResultData implements ResponseData
{
    public function __construct(
        public readonly DraftData $draft,
        public readonly bool $preview_cleared,
    ) {
    }
}

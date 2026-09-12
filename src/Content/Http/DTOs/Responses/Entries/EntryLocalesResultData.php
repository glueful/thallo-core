<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs\Responses\Entries;

use Glueful\Http\Contracts\ResponseData;
use Glueful\Validation\Attributes\ArrayOf;

final class EntryLocalesResultData implements ResponseData
{
    public function __construct(
        /** @var list<EntryLocaleData> */
        #[ArrayOf(EntryLocaleData::class)]
        public readonly array $locales,
    ) {
    }
}

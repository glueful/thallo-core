<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs\Responses\Entries;

use Glueful\Http\Contracts\ResponseData;

final class EntryLocaleData implements ResponseData
{
    public function __construct(
        public readonly string $locale,
        public readonly bool $has_draft,
        public readonly bool $is_published,
        public readonly ?string $route_slug,
        public readonly ?\DateTimeInterface $draft_updated_at,
        public readonly ?\DateTimeInterface $published_at,
        public readonly ?EntryLocaleScheduleData $scheduled,
    ) {
    }
}

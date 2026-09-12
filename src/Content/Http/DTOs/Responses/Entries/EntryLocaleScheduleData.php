<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs\Responses\Entries;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only schema holder: mirrors the `scheduled` sub-object that
 * {@see \Thallo\Core\Content\Repositories\EntryRepository::emptyScheduleSummary()} assembles from
 * `entry_schedules` rows. NEVER constructed at runtime — it exists only so the OpenAPI
 * generator can reflect a typed schema for the `scheduled` key inside each locale summary
 * item. `publish` and `unpublish` are typed as `?string` (not `?\DateTimeInterface`)
 * because the repository returns ISO-8601 timestamp strings directly from the database
 * column, matching the wire shape exactly; the DTO is never instantiated.
 */
final class EntryLocaleScheduleData implements ResponseData
{
    public function __construct(
        public readonly ?string $publish,
        public readonly ?string $unpublish,
        public readonly ?EntryLocaleScheduleFailureData $last_failure,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs\Responses\Entries;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only schema holder: mirrors the `last_failure` sub-object that
 * {@see \Thallo\Core\Content\Repositories\EntryRepository::emptyScheduleSummary()} populates from
 * the most-recent `failed` row in `entry_schedules`. NEVER constructed at runtime — it
 * exists only so the OpenAPI generator can reflect a typed schema for the `last_failure`
 * key nested inside the `scheduled` block of a locale summary item. `run_at` is typed as
 * `?string` because the repository returns the ISO-8601 timestamp string from the database
 * column directly; the DTO is never instantiated.
 */
final class EntryLocaleScheduleFailureData implements ResponseData
{
    public function __construct(
        public readonly string $action,
        public readonly ?string $run_at,
        public readonly string $reason,
    ) {
    }
}

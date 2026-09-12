<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Request body for `PUT /v1/admin/entries/{uuid}/draft/{locale}`
 * ({@see \Thallo\Core\Content\Http\Controllers\EntryController::saveDraft()}).
 *
 * Hydrated by the router (v2). `fields` is a dynamic content map (arbitrary keys/values keyed
 * by the content type's field names) so it stays a bare `array` — no `#[ArrayOf]`; the per-field
 * semantic validation against the schema is the controller's {@see \Thallo\Core\Content\Validation\FieldValidator}
 * (a failure → 422). `lock_version` uses `numeric` (not `integer`) so a JSON number or numeric
 * string is accepted and coerced to int, matching the controller's previous `(int)` tolerance; a
 * stale value still trips the repository's optimistic-lock CAS → 409.
 */
final class SaveDraftData implements RequestData
{
    public function __construct(
        /** @var array<string,mixed> Draft field values keyed by the content type's field names. */
        #[Rule('array')]
        public readonly array $fields = [],
        /** @var int|null Optimistic-lock counter echoed from the last read. */
        #[Rule('numeric')]
        public readonly ?int $lock_version = null,
    ) {
    }
}

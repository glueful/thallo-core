<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs\Requests;

use Glueful\Validation\Attributes\FromQuery;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Query parameters for the draft-inclusive admin entry list
 * ({@see \Thallo\Core\Content\Http\Controllers\EntryController::index()}). The router hydrates this from
 * the query string; the required-`type` rule fails hydration (→ 422) BEFORE the controller runs.
 * Int clamping for page/perPage stays in the controller.
 */
final class EntryListQuery implements RequestData
{
    public function __construct(
        #[FromQuery(description: 'Content type slug to list.')]
        #[Rule('required|string')]
        public readonly string $type,
        #[FromQuery(description: 'Case-insensitive substring filter on the derived display title.')]
        #[Rule('string')]
        public readonly ?string $q = null,
        // Query-string ints arrive as strings; `numeric` accepts "2" where `integer` (a gettype()
        // check) would reject it. The `?int` param then coerces it.
        #[FromQuery(description: 'Page number (default 1).')]
        #[Rule('numeric')]
        public readonly ?int $page = null,
        #[FromQuery(description: 'Items per page (clamped to thallo.delivery.max_per_page; default default_per_page).')]
        #[Rule('numeric')]
        public readonly ?int $perPage = null,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs\Requests\Delivery;

use Glueful\Validation\Attributes\FromQuery;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Query parameters for `GET /v1/content/{type}`
 * ({@see \Thallo\Core\Content\Http\Controllers\DeliveryController::index()}).
 *
 * Hydrated by the router from the query string. `$filter` carries the nested bracket
 * array (`filter[field][op]=value`) untouched — a `#[Rule('array')]` with no
 * `#[ArrayOf]` preserves the nested structure, so it matches the previous
 * `$request->query->all('filter')` exactly. Int clamping stays in the controller.
 */
final class DeliveryListQuery implements RequestData
{
    public function __construct(
        #[FromQuery(
            description: 'Content locale to read. Single-entry reads walk the configured i18n fallback chain; '
                . 'when omitted, this defaults to the i18n default locale.',
        )]
        #[Rule('string')]
        public readonly ?string $locale = null,
        #[FromQuery(
            description: 'Sort by a filterable field, `sort=field:asc` or `sort=field:desc`. '
                . 'Defaults to `published_at:desc`.',
        )]
        #[Rule('string')]
        public readonly ?string $sort = null,
        #[FromQuery(
            description: 'Opaque keyset cursor taken from a previous response\'s `next_cursor`. '
                . 'Cursor (default) mode only.',
        )]
        #[Rule('string')]
        public readonly ?string $cursor = null,
        #[FromQuery(
            description: 'Page number. Supplying `page` or `perPage` switches the response to the '
                . 'offset-pagination envelope.',
        )]
        // Query-string ints arrive as strings; `numeric` accepts "2" where `integer`
        // (a gettype() check) would reject it. The `?int` param then coerces it.
        #[Rule('numeric')]
        public readonly ?int $page = null,
        #[FromQuery(
            description: 'Items per page for offset pagination (clamped to delivery.max_per_page).',
        )]
        #[Rule('numeric')]
        public readonly ?int $perPage = null,
        /** @var array<string,mixed> */
        #[FromQuery(
            description: 'Typed filters on filterable fields using bracket syntax `filter[field][op]=value`. '
                . 'Operators: eq, neq, gt, gte, lt, lte, in. Only fields declared filterable are accepted.',
        )]
        #[Rule('array')]
        public readonly array $filter = [],
    ) {
    }

    /**
     * Whether the request opts into offset pagination. Presence of either `page` or `perPage`
     * switches index() from the default keyset-cursor list to the framework offset envelope.
     */
    public function wantsPagination(): bool
    {
        return $this->page !== null || $this->perPage !== null;
    }
}

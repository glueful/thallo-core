<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs\Requests\Delivery;

use Glueful\Validation\Attributes\FromQuery;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Query parameters for `GET /v1/content/{type}/facets`
 * ({@see \Thallo\Core\Content\Http\Controllers\TaxonomyController::facets()}).
 */
final class DeliveryFacetsQuery implements RequestData
{
    public function __construct(
        #[FromQuery(
            description: 'Comma-separated filterable reference field names to count, '
                . 'e.g. `fields=categories,tags`.',
        )]
        #[Rule('required|string')]
        public readonly string $fields = '',
        #[FromQuery(description: 'Content locale; defaults to the i18n default locale.')]
        #[Rule('string')]
        public readonly ?string $locale = null,
        #[FromQuery(description: 'Max terms per field (default 100, capped at 500).')]
        #[Rule('numeric')]
        public readonly ?int $limit = null,
    ) {
    }
}

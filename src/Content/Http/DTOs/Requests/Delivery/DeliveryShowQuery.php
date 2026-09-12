<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs\Requests\Delivery;

use Glueful\Validation\Attributes\FromQuery;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Query parameters for `GET /v1/content/{type}/{slugOrUuid}`
 * ({@see \Thallo\Core\Content\Http\Controllers\DeliveryController::show()}).
 *
 * Hydrated by the router from the query string. The requested locale seeds the i18n
 * fallback chain; when omitted it defaults to the i18n default locale.
 */
final class DeliveryShowQuery implements RequestData
{
    public function __construct(
        #[FromQuery(description: 'Content locale to read (defaults to the i18n default locale).')]
        #[Rule('string')]
        public readonly ?string $locale = null,
    ) {
    }
}

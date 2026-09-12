<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs\Responses\Delivery;

use Glueful\Http\Contracts\ResponseData;
use Glueful\Validation\Attributes\ArrayOf;

/**
 * Doc-only schema holder: the cursor-mode success-envelope `data` payload returned by
 * the index endpoint of {@see \Thallo\Core\Content\Http\Controllers\DeliveryController} (HTTP 200,
 * cursor mode — i.e. no `page`/`perPage` query params).
 * NEVER constructed at runtime — it exists only so the OpenAPI generator can reflect a
 * typed schema for the `items` array (each element is a {@see DeliveryItemData}) and the
 * opaque `next_cursor` keyset token. Offset-pagination mode (explicit `?page`/`?perPage`)
 * uses a different framework envelope and is not documented here.
 */
final class DeliveryListData implements ResponseData
{
    /** @param list<DeliveryItemData> $items */
    public function __construct(
        #[ArrayOf(DeliveryItemData::class)]
        public readonly array $items,
        public readonly ?string $next_cursor,
    ) {
    }
}

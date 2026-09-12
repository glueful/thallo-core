<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs\Responses\Delivery;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only schema holder: mirrors the array returned by the private
 * {@see \Thallo\Core\Content\Http\Controllers\DeliveryController::item()} method, which
 * shapes one hydrated publication-spine row into the public delivery envelope.
 * NEVER constructed at runtime — it exists only so the OpenAPI generator can reflect a
 * typed schema for each element of `data.items` in the cursor-mode list response and
 * for the bare `data` payload of the single-entry show response of
 * {@see \Thallo\Core\Content\Http\Controllers\DeliveryController} (HTTP 200).
 * `fields` is typed as `object` because the map is freeform and content-type-specific;
 * the OpenAPI generator emits `{type: object}` and drift tests never recurse into it.
 * `published_at` is a nullable datetime string surfaced as `?\DateTimeInterface` so
 * the generator emits `{type: string, format: date-time}`.
 */
final class DeliveryItemData implements ResponseData
{
    public function __construct(
        public readonly ?string $uuid,
        public readonly ?string $locale,
        public readonly ?int $version,
        public readonly ?\DateTimeInterface $published_at,
        public readonly object $fields,
    ) {
    }
}

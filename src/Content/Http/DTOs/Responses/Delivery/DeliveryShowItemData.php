<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs\Responses\Delivery;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only schema holder for the single-entry delivery response. It extends the
 * list-item shell with SEO routing metadata because show() can compute canonical and
 * hreflang links for one entry without adding list-path fanout.
 */
final class DeliveryShowItemData implements ResponseData
{
    public function __construct(
        public readonly ?string $uuid,
        public readonly ?string $locale,
        public readonly ?int $version,
        public readonly ?\DateTimeInterface $published_at,
        public readonly object $fields,
        public readonly object $seo,
    ) {
    }
}

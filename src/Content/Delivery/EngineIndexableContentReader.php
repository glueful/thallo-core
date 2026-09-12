<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Delivery;

use Thallo\Core\Content\Seo\CanonicalPathBuilder;
use Thallo\Contracts\Search\IndexableContent;
use Thallo\Contracts\Search\IndexableContentReader;
use Thallo\Contracts\Search\IndexablePage;

/**
 * Adapts the delivery spine to the search IndexableContentReader contract. Both read
 * paths go through DeliveryRepository's single indexable join (only PUBLISHED, routed,
 * active entries with a live content type) and one shared row→IndexableContent mapper,
 * so the per-publish path and the backfill can never drift apart.
 */
final class EngineIndexableContentReader implements IndexableContentReader
{
    public function __construct(
        private readonly DeliveryRepository $delivery,
        private readonly CanonicalPathBuilder $pathBuilder,
    ) {
    }

    public function getIndexablePublished(string $entryUuid, string $locale): ?IndexableContent
    {
        $row = $this->delivery->findIndexableRow($entryUuid, $locale);
        return $row === null ? null : $this->toIndexable($row);
    }

    public function enumerateIndexablePublished(
        int $limit,
        int $offset = 0,
        ?string $typeSlug = null,
        ?string $locale = null,
    ): IndexablePage {
        $items = [];
        foreach ($this->delivery->enumerateIndexable($limit, $offset, $typeSlug, $locale) as $row) {
            $items[] = $this->toIndexable($row);
        }

        return new IndexablePage($items, $limit, $offset);
    }

    /** @param array<string,mixed> $row one row of DeliveryRepository's indexable join */
    private function toIndexable(array $row): IndexableContent
    {
        $slug = (string) $row['slug'];
        $typeSlug = (string) $row['content_type_slug'];
        $locale = (string) $row['locale'];
        $fields = is_string($row['fields'] ?? null)
            ? (json_decode((string) $row['fields'], true) ?? [])
            : (array) ($row['fields'] ?? []);

        return new IndexableContent(
            entryUuid: (string) $row['entry_uuid'],
            locale: $locale,
            contentTypeUuid: (string) $row['content_type_uuid'],
            contentTypeSlug: $typeSlug,
            publicDelivery: (bool) ($row['public_delivery'] ?? false),
            // Search results link straight to this — it must be the CANONICAL
            // form (root-collapsed, default locale collapsed), same builder as
            // every other href surface.
            href: $this->pathBuilder->pathFor(
                $typeSlug,
                (bool) ($row['mount_at_root'] ?? false),
                $locale,
                $slug,
            ),
            entryLabel: $slug,
            fields: (array) $fields,
            lastmod: Timestamps::iso($row['published_at'] ?? null),
        );
    }
}

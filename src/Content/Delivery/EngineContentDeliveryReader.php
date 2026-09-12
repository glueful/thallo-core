<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Delivery;

use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Seo\CanonicalPathBuilder;
use Thallo\Core\Content\Seo\CanonicalProjector;
use Thallo\Contracts\Delivery\ContentDeliveryReader;

/**
 * Adapts DeliveryRepository (publication-spine queries) to the ContentDeliveryReader
 * contract. find tries route(slug) first, then falls back to uuid lookup.
 */
final class EngineContentDeliveryReader implements ContentDeliveryReader
{
    public function __construct(
        private readonly DeliveryRepository $delivery,
        private readonly CanonicalPathBuilder $pathBuilder,
        private readonly CanonicalProjector $canonical,
        private readonly ContentTypeRepository $types,
    ) {
    }

    public function listPublished(string $contentTypeUuid, string $locale, int $limit = 20): array
    {
        return $this->delivery->listPublished($contentTypeUuid, $locale, $limit);
    }

    public function findPublished(string $contentTypeUuid, string $locale, string $slugOrUuid): ?array
    {
        return $this->delivery->findPublishedByRoute($contentTypeUuid, $locale, $slugOrUuid)
            ?? $this->delivery->findPublishedByUuid($contentTypeUuid, $locale, $slugOrUuid);
    }

    public function enumeratePublishedForSitemap(int $limit, int $offset = 0): array
    {
        $page = $this->delivery->enumeratePublishedForSitemap($limit, $offset);
        $types = $this->typeInfoMap();

        $items = [];
        foreach ($page['rows'] as $row) {
            $typeUuid = (string) $row['content_type_uuid'];
            $info = $types[$typeUuid] ?? null;
            if ($info === null) {
                continue; // orphaned type — skip rather than emit a broken URL
            }
            $typeSlug = $info['slug'];
            $locale = (string) $row['locale'];
            $slug = (string) $row['slug'];
            $entryUuid = (string) $row['entry_uuid'];

            $alternates = [];
            foreach ($this->canonical->project($entryUuid, $typeUuid, $typeSlug, $locale)['alternates'] as $alt) {
                $alternates[] = ['locale' => (string) $alt['locale'], 'href' => (string) $alt['href']];
            }

            $items[] = [
                // Sitemaps must list CANONICAL URLs — root-collapsed and
                // default-locale collapsed, same builder as every href surface.
                'href' => $this->pathBuilder->pathFor($typeSlug, $info['mount_at_root'], $locale, $slug),
                'lastmod' => Timestamps::iso($row['published_at'] ?? null),
                'alternates' => $alternates,
            ];
        }

        return ['items' => $items, 'total' => $page['total'], 'limit' => $limit, 'offset' => $offset];
    }

    /**
     * Built per call, NOT memoized: this reader is a shared singleton, and a
     * process-lifetime uuid=>type cache would go stale the moment types
     * change (and poisons truncate-between-tests suites). One query per
     * sitemap page is the honest cost.
     *
     * @return array<string,array{slug:string,mount_at_root:bool}> uuid => type info
     */
    private function typeInfoMap(): array
    {
        $map = [];
        foreach ($this->types->all() as $type) {
            $map[(string) $type['uuid']] = [
                'slug' => (string) $type['slug'],
                'mount_at_root' => (bool) ($type['mount_at_root'] ?? false),
            ];
        }
        return $map;
    }
}

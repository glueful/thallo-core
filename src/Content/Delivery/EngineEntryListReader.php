<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Delivery;

use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\PublishedReferenceRepository;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Contracts\Delivery\EntryListReader;
use Thallo\Contracts\Delivery\ReferenceTargetResolver;

/**
 * Template-facing published-entry listing (the blog_posts block, etc.). Same
 * visibility gate as the listing page, but a template fail (unknown/non-deliverable
 * type, unresolved category) returns {[], []} and renders nothing. Limit is clamped
 * 1..12 server-side regardless of caller input. Shares the row→item+href shaping
 * path with the route resolver via ListingItemShaper.
 */
final class EngineEntryListReader implements EntryListReader
{
    public function __construct(
        private readonly ContentTypeRepository $types,
        private readonly DeliveryRepository $delivery,
        private readonly PublishedReferenceRepository $projection,
        private readonly ReferenceTargetResolver $terms,
        private readonly ListingItemShaper $listShaper,
    ) {
    }

    public function list(string $type, array $opts, string $locale): array
    {
        $none = ['items' => [], 'cache_tags' => []];

        $typeRow = $this->types->findBySlug($type);
        if ($typeRow === null || !$this->visible($typeRow)) {
            return $none;
        }
        $typeUuid = (string) $typeRow['uuid'];
        $typeSlug = (string) $typeRow['slug'];

        $limit = max(1, min(12, (int) ($opts['limit'] ?? 3)));
        $order = ($opts['order'] ?? 'newest') === 'oldest'
            ? [
                'sql' => 'ORDER BY p.published_at ASC, v.id DESC',
                'expr' => 'p.published_at', 'direction' => 'ASC',
                'field' => null, 'column' => 'published_at',
            ]
            : SortCompiler::defaultOrder();

        // Category filter — deterministic: first filterable reference field in schema order.
        $filter = null;
        $termUuid = null;
        $targetSlug = null;
        $category = trim((string) ($opts['category'] ?? ''));
        if ($category !== '') {
            $schema = ContentTypeSchema::fromArray((array) ($typeRow['schema'] ?? []));
            $catField = null;
            foreach ($schema->fields() as $f) {
                if ($f->type === 'reference' && $f->filterable) {
                    $catField = $f;
                    break;
                }
            }
            if ($catField === null) {
                return $none;
            }
            $targetRow = $this->types->findBySlug((string) ($catField->referenceType ?? ''));
            if ($targetRow === null || !$this->visible($targetRow)) {
                return $none;
            }
            try {
                $targets = $this->terms->resolve($catField, $locale, [$category]);
            } catch (InvalidFilterException) {
                return $none;
            }
            $termUuid = $targets[0] ?? null;
            if ($termUuid === null) {
                return $none;
            }
            $targetSlug = (string) $targetRow['slug'];
            $filter = $this->projection->membershipPredicate($typeUuid, $catField->name, $termUuid);
        }

        $result = $this->delivery->paginatePublished($typeUuid, $locale, 1, $limit, $filter, $order);
        $expanded = new ExpandedTargets();
        $items = $this->listShaper->shape($result['data'], $typeRow, $locale, $expanded);

        // Broad listing dependency FIRST (correctness — resolved type SLUG, not the
        // submitted string), then per-item entry tags, expansion tags, and (category)
        // the term's entry tag + the term type's slug tag.
        $tags = ['thallo:type:' . $typeSlug];
        foreach ($items as $it) {
            if (($it['uuid'] ?? null) !== null) {
                $tags[] = 'thallo:entry:' . (string) $it['uuid'];
            }
        }
        foreach ($expanded->entryUuids() as $u) {
            $tags[] = 'thallo:entry:' . $u;
        }
        if ($termUuid !== null) {
            $tags[] = 'thallo:entry:' . $termUuid;
            $tags[] = 'thallo:type:' . (string) $targetSlug;
        }

        return ['items' => $items, 'cache_tags' => array_values(array_unique($tags))];
    }

    /** @param array<string,mixed> $typeRow */
    private function visible(array $typeRow): bool
    {
        return DeliveryVisibility::isAccessible(
            (bool) ($typeRow['public_delivery'] ?? false),
            (string) ($typeRow['slug'] ?? ''),
            null, // templates are an anonymous surface
        );
    }
}

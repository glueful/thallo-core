<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Delivery;

use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\PublishedReferenceRepository;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Contracts\Delivery\FacetCountsReader;

/**
 * Template-facing facet counts over the published-reference projection, with the SAME
 * gates as the facets endpoint (anonymous visibility on source AND target, filterable
 * reference field, limit clamped) — but the fail posture differs by consumer: the
 * endpoint 404s fail-closed; a TEMPLATE gets {[], []} and renders nothing.
 */
final class EngineFacetCountsReader implements FacetCountsReader
{
    private const LIMIT_MAX = 500;

    public function __construct(
        private readonly ContentTypeRepository $types,
        private readonly PublishedReferenceRepository $projection,
    ) {
    }

    public function counts(string $typeSlug, string $field, string $locale, int $limit = 100): array
    {
        $none = ['items' => [], 'cache_tags' => []];

        $typeRow = $this->types->findBySlug($typeSlug);
        if ($typeRow === null || !$this->visible($typeRow)) {
            return $none;
        }
        $schema = ContentTypeSchema::fromArray((array) ($typeRow['schema'] ?? []));
        $fieldDef = $schema->field($field);
        if ($fieldDef === null || $fieldDef->type !== 'reference' || !$fieldDef->filterable) {
            return $none;
        }
        $targetRow = $this->types->findBySlug((string) ($fieldDef->referenceType ?? ''));
        if ($targetRow === null || !$this->visible($targetRow)) {
            return $none;
        }

        $items = $this->projection->facetCounts(
            (string) $typeRow['uuid'],
            $fieldDef,
            (string) $targetRow['uuid'],
            $locale,
            max(1, min($limit, self::LIMIT_MAX)),
        );

        return [
            'items' => $items,
            // Valid facet — even with zero counts — tags the page (review P1).
            'cache_tags' => [
                'thallo:type:' . (string) $typeRow['slug'],
                'thallo:type:' . (string) $targetRow['slug'],
            ],
        ];
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

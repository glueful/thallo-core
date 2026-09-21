<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Delivery;

use Thallo\Contracts\Delivery\EntryTreeReader;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Schema\ContentTypeSchema;

/**
 * {@see EntryTreeReader} over the published store: the same visibility gate and the same
 * row→item+href shaping as the listing page ({@see ListingItemShaper}), reduced to navigation.
 */
final class EngineEntryTreeReader implements EntryTreeReader
{
    public function __construct(
        private readonly ContentTypeRepository $types,
        private readonly DeliveryRepository $delivery,
        private readonly ListingItemShaper $listShaper,
    ) {
    }

    public function tree(string $type, string $locale, string $groupField, string $orderField): array
    {
        $typeRow = $this->types->findBySlug($type);
        $visible = $typeRow !== null && DeliveryVisibility::isAccessible(
            (bool) ($typeRow['public_delivery'] ?? false),
            (string) ($typeRow['slug'] ?? ''),
            null, // templates are an anonymous surface
        );
        if ($typeRow === null || !$visible) {
            return ['groups' => [], 'items' => [], 'cache_tags' => []];
        }

        $result = $this->delivery->paginatePublished((string) $typeRow['uuid'], $locale, 1, self::MAX);
        $shaped = $this->listShaper->shape($result['data'], $typeRow, $locale, new ExpandedTargets());

        // The sections, in the order the type's author wrote them.
        $order = [];
        foreach (ContentTypeSchema::fromArray((array) ($typeRow['schema'] ?? []))->fields() as $field) {
            if ($field->name === $groupField && $field->type === 'enum') {
                $order = array_values(array_map('strval', $field->enumValues));
            }
        }

        $rows = [];
        foreach ($shaped as $item) {
            $fields = (array) ($item['fields'] ?? []);
            if (!is_string($item['href'] ?? null)) {
                continue; // a page with no route is not somewhere a reader can go
            }
            $group = is_string($fields[$groupField] ?? null) && in_array($fields[$groupField], $order, true)
                ? $fields[$groupField]
                : '';
            $rows[] = [
                'uuid' => (string) $item['uuid'],
                'slug' => (string) basename((string) $item['href']),
                'href' => (string) $item['href'],
                'title' => is_string($fields['title'] ?? null) ? $fields['title'] : '',
                'summary' => is_string($fields['summary'] ?? null) && $fields['summary'] !== ''
                    ? $fields['summary']
                    : null,
                'group' => $group,
                '_order' => is_numeric($fields[$orderField] ?? null) ? (float) $fields[$orderField] : PHP_FLOAT_MAX,
            ];
        }
        $rank = array_flip($order);
        usort($rows, static fn (array $a, array $b): int => [
            $rank[$a['group']] ?? PHP_INT_MAX, $a['_order'], mb_strtolower($a['title']),
        ] <=> [
            $rank[$b['group']] ?? PHP_INT_MAX, $b['_order'], mb_strtolower($b['title']),
        ]);

        $groups = [];
        $items = [];
        foreach ($rows as $row) {
            unset($row['_order']);
            $items[] = $row;
            $key = $row['group'];
            $groups[$key] ??= ['key' => $key, 'label' => self::label($key), 'items' => []];
            $groups[$key]['items'][] = $row;
        }

        return [
            'groups' => array_values($groups),
            'items' => $items,
            'cache_tags' => ['thallo:type:' . (string) $typeRow['slug']],
        ];
    }

    /** `getting-started` reads "Getting started". */
    private static function label(string $key): string
    {
        return $key === '' ? '' : ucfirst(str_replace(['-', '_'], ' ', $key));
    }
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Delivery;

use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Content\Seo\CanonicalPathBuilder;
use Glueful\Database\Connection;
use Glueful\Support\FieldSelection\FieldSelector;
use Symfony\Component\HttpFoundation\Request;

/**
 * Shapes published rows into template list items (full item + canonical href),
 * collecting expansion targets. Extracted from EnginePublicRouteResolver::listItems
 * so the route resolver and EntryListReader share ONE shaping path. App-internal
 * collaborator (no contract) — the only public contract this feature adds is
 * EntryListReader.
 */
final class ListingItemShaper
{
    public function __construct(
        private readonly Connection $db,
        private readonly DeliveryItemShaper $shaper,
        private readonly CanonicalPathBuilder $canonical,
    ) {
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<string,mixed> $typeRow
     * @return list<array<string,mixed>>
     */
    public function shape(array $rows, array $typeRow, string $locale, ExpandedTargets $expanded): array
    {
        if ($rows === []) {
            return [];
        }
        $typeUuid = (string) $typeRow['uuid'];
        $typeSlug = (string) $typeRow['slug'];
        $schema = ContentTypeSchema::fromArray((array) ($typeRow['schema'] ?? []));
        $selector = FieldSelector::fromRequest(Request::create('/')); // empty = full item

        $shaped = $this->shaper->shape($rows, $schema, $selector, $locale, $typeUuid, null, $expanded);

        $uuids = array_values(array_filter(array_map(
            static fn(array $r): string => (string) ($r['entry_uuid'] ?? ''),
            $shaped,
        )));
        $slugByEntry = [];
        if ($uuids !== []) {
            $placeholders = implode(', ', array_fill(0, count($uuids), '?'));
            // Constrained by content_type_uuid: the route table's real identity is
            // (content_type_uuid, locale, slug) — entry_uuid alone can carry stale
            // rows under another type, which would render a wrong-type href.
            $routeRows = $this->db->table('entry_routes')
                ->select(['entry_uuid', 'slug'])
                ->whereRaw("entry_uuid IN ({$placeholders})", $uuids)
                ->where('content_type_uuid', '=', $typeUuid)
                ->where('locale', '=', $locale)
                ->get();
            foreach ($routeRows as $r) {
                $slugByEntry[(string) $r['entry_uuid']] = (string) $r['slug'];
            }
        }

        $items = [];
        foreach ($shaped as $row) {
            $item = $this->shaper->item($row);
            $slug = $slugByEntry[(string) ($row['entry_uuid'] ?? '')] ?? null;
            $item['href'] = $slug === null
                ? null
                : $this->canonical->pathFor(
                    $typeSlug,
                    (bool) ($typeRow['mount_at_root'] ?? false),
                    $locale,
                    $slug,
                );
            $items[] = $item;
        }
        return $items;
    }
}

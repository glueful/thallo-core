<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Delivery;

use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Glueful\Support\FieldSelection\FieldSelector;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Content\EntryExistenceReader;
use Thallo\Contracts\Delivery\PublishedEntryBlocksReader;

/**
 * Engine-backed {@see PublishedEntryBlocksReader}: tenant/existence via
 * {@see EntryExistenceReader} (the same seam {@see \Thallo\Commerce\Links\ProductLinkService}
 * already trusts for link reads), the public-delivery gate via {@see DeliveryVisibility}, and
 * the actual published fields via {@see DeliveryRepository::findPublishedByUuid()} — the
 * leak-proof spine read (`entry_publications` joined to the pinned `entry_versions`) that,
 * unlike {@see EnginePublicRouteResolver::resolveEntry()}, never touches `entry_routes` at
 * all. Reference expansion + reserved-key stripping goes through
 * {@see DeliveryItemShaper::shape()} — the SAME shaping every other render-facing read uses —
 * so a reference embedded inside the entry's blocks resolves exactly like it would anywhere
 * else in the render pipeline.
 */
final class EnginePublishedEntryBlocksReader implements PublishedEntryBlocksReader
{
    public function __construct(
        private readonly EntryExistenceReader $existence,
        private readonly ContentTypeRepository $types,
        private readonly DeliveryRepository $delivery,
        private readonly DeliveryItemShaper $shaper,
    ) {
    }

    public function findPublishedBlocks(string $entryUuid, string $tenant, string $locale): ?array
    {
        $entry = $this->existence->exists($entryUuid, $tenant);
        if ($entry === null) {
            return null; // missing, soft-deleted, or belongs to a different tenant
        }

        $typeUuid = $entry['content_type_uuid'];
        $typeRow = $this->types->findByUuid($typeUuid);
        if ($typeRow === null || !$this->isPubliclyDeliverable($typeRow)) {
            return null;
        }
        $typeSlug = (string) $typeRow['slug'];

        // Route-independent published read: the publication spine joined to the pinned
        // version, filtered by content type + locale + entry uuid — no entry_routes lookup.
        $row = $this->delivery->findPublishedByUuid($typeUuid, $locale, $entryUuid);
        if ($row === null) {
            return null; // no published version in this locale
        }

        $schema = ContentTypeSchema::fromArray((array) ($typeRow['schema'] ?? []));
        // A bare request yields the empty selector (no ?fields/?expand) — full, unprojected
        // fields, matching DeliveryItemShaper::shapePublic()'s own convention.
        $selector = FieldSelector::fromRequest(Request::create('/'));
        $shaped = $this->shaper->shape([$row], $schema, $selector, $locale, $typeUuid, null);
        $fields = $shaped[0]['fields'] ?? [];

        return ['entry_uuid' => $entryUuid, 'type' => $typeSlug, 'fields' => $fields];
    }

    /** @param array<string,mixed> $typeRow */
    private function isPubliclyDeliverable(array $typeRow): bool
    {
        return DeliveryVisibility::isAccessible(
            (bool) ($typeRow['public_delivery'] ?? false),
            (string) ($typeRow['slug'] ?? ''),
            null, // render is an anonymous surface — no api_key_scopes
        );
    }
}

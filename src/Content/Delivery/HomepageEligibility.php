<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Delivery;

use Thallo\Core\Content\Localization\ContentLocaleService;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Glueful\Database\Connection;

/**
 * Explains why an entry cannot be the homepage. The authority stays the public route resolver
 * (Settings → General refuses whatever it does not resolve as live content); this mirrors its
 * conditions one by one so the refusal can NAME the failing one. A page that reads "published"
 * in the list can still fail the condition the list never shows: no route saved in the default
 * locale.
 */
final class HomepageEligibility
{
    public function __construct(
        private readonly Connection $db,
        private readonly ContentTypeRepository $types,
        private readonly ContentLocaleService $locales,
        private readonly DeliveryRepository $delivery,
    ) {
    }

    /** @return string|null The reason the entry is not eligible, or null when these checks all pass. */
    public function reasonNotEligible(string $entryUuid): ?string
    {
        $entry = $this->db->table('entries')->select(['content_type_uuid', 'status'])
            ->where('uuid', '=', $entryUuid)->first();
        if ($entry === null) {
            return "no entry with id \"{$entryUuid}\"";
        }
        if (($entry['status'] ?? null) === 'deleted') {
            return 'the entry is deleted';
        }

        $type = $this->types->findByUuid((string) $entry['content_type_uuid']);
        if ($type === null) {
            return 'the entry\'s content type no longer exists';
        }
        $typeSlug = (string) ($type['slug'] ?? '');
        if (!(bool) ($type['public_delivery'] ?? false)) {
            return "content type \"{$typeSlug}\" is not publicly delivered";
        }

        $locale = $this->locales->default();
        if ($this->delivery->findPublishedByUuid((string) $type['uuid'], $locale, $entryUuid) === null) {
            return "not published in locale \"{$locale}\"";
        }

        $route = $this->db->table('entry_routes')->select(['slug'])
            ->where('entry_uuid', '=', $entryUuid)->where('locale', '=', $locale)->first();
        if ($route === null) {
            return "published in locale \"{$locale}\" but has no route yet — save a slug in the Publishing panel";
        }

        return null;
    }
}

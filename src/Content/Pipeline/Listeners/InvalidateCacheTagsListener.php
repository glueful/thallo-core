<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Pipeline\Listeners;

use Thallo\Core\Content\Events\BaseEntryEvent;
use Thallo\Core\Content\Events\BaseModelEvent;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Glueful\Cache\CacheStore;
use Psr\Container\ContainerInterface;

/**
 * Purges the delivery layer's surrogate cache keys when content changes (V1_DESIGN §5).
 *
 * The delivery API (Thallo\Core\Content\Http\DeliveryEtag::cacheTag) tags every response with
 * `thallo:entry:{uuid}` for each member entry plus `thallo:type:{slug}` for the type. This
 * listener invalidates the SAME strings so a publish/unpublish/delete/model change drops
 * the stale cache. A byte-for-byte match is essential — a mismatch silently serves stale
 * content forever.
 *
 * Tag sets:
 *   - entry events  -> [thallo:entry:{uuid}, thallo:type:{slug}]
 *   - model events  -> [thallo:type:{slug}]
 *
 * Entry events carry the content-type UUID (not the slug), so the type tag is resolved
 * uuid -> slug via ContentTypeRepository (memoised). Model events already carry the slug.
 *
 * The CacheStore is resolved from the container per-invocation rather than captured in the
 * constructor: this listener is a long-lived singleton registered at boot, so resolving
 * lazily means it always uses the current `cache.store` binding (and keeps the wiring
 * testable by allowing the cache to be substituted after boot).
 *
 * Registered via EventService::addListener(..., '@' . self::class) — the '@serviceId'
 * form resolves this service lazily and invokes it as a callable, so the entry point is
 * __invoke(object $event). Idempotent: invalidating an already-clear tag is a no-op.
 */
final class InvalidateCacheTagsListener
{
    /** @var array<string, string> uuid -> slug memo for the lifetime of this instance. */
    private array $slugByUuid = [];

    public function __construct(
        private readonly ContainerInterface $container,
        private readonly ContentTypeRepository $types,
    ) {
    }

    public function __invoke(object $event): void
    {
        $tags = $this->tagsFor($event);
        if ($tags === []) {
            return;
        }
        $this->cache()->invalidateTags($tags);
    }

    private function cache(): CacheStore
    {
        /** @var CacheStore $store */
        $store = $this->container->get(CacheStore::class);
        return $store;
    }

    /**
     * Map an event to the surrogate keys it invalidates.
     *
     * @return list<string>
     */
    private function tagsFor(object $event): array
    {
        if ($event instanceof BaseEntryEvent) {
            $slug = $this->resolveSlug($event->type);
            $tags = ['thallo:entry:' . $event->entry];
            if ($slug !== null) {
                $tags[] = 'thallo:type:' . $slug;
            }
            return $tags;
        }

        if ($event instanceof BaseModelEvent) {
            // Model events carry the slug directly.
            return ['thallo:type:' . $event->type];
        }

        return [];
    }

    /**
     * Resolve a content-type UUID to its slug (memoised). Returns null when the type can
     * no longer be found (e.g. a deleted type) — the entry tag still gets invalidated.
     */
    private function resolveSlug(string $typeUuid): ?string
    {
        if (array_key_exists($typeUuid, $this->slugByUuid)) {
            return $this->slugByUuid[$typeUuid];
        }
        $row = $this->types->findByUuid($typeUuid);
        $slug = $row === null ? null : (string) $row['slug'];
        if ($slug !== null) {
            $this->slugByUuid[$typeUuid] = $slug;
        }
        return $slug;
    }
}

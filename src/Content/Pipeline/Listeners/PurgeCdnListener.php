<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Pipeline\Listeners;

use Thallo\Core\Content\Events\BaseEntryEvent;
use Thallo\Core\Content\Events\BaseModelEvent;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Glueful\Cache\Contracts\EdgeCacheInterface;
use Psr\Container\ContainerInterface;

/**
 * Purges the CDN edge cache by surrogate tag when content changes (V1_DESIGN §5).
 *
 * CAPABILITY-GATED. The default Thallo install enables users/aegis/media/email but NOT
 * glueful/cdn. Core always binds {@see EdgeCacheInterface} — to the no-op
 * {@see \Glueful\Cache\NullEdgeCache} when no CDN integration is installed — so a naive
 * container `has()` check is ALWAYS true and would call purge on the null cache. The real
 * "is there a CDN" signal is therefore the seam's own state: {@see EdgeCacheInterface::isEnabled()}
 * (NullEdgeCache returns false; a real glueful/cdn integration returns true). When the edge
 * cache is the disabled no-op this listener is a CLEAN skip — no error, no exception.
 *
 * When a real CDN IS present, it purges the SAME surrogate tags the delivery layer emits and
 * the cache-invalidation listener invalidates:
 *   - entry events -> [thallo:entry:{uuid}, thallo:type:{slug}]
 *   - model events -> [thallo:type:{slug}]
 * Entry events carry the content-type UUID (not the slug), so the type tag is resolved
 * uuid -> slug via ContentTypeRepository (memoised) — mirroring InvalidateCacheTagsListener.
 *
 * The EdgeCacheInterface is resolved from the container per-invocation rather than captured
 * in the constructor: this listener is a long-lived singleton registered at boot, so
 * resolving lazily means it always uses the current binding (and lets a test substitute a
 * real edge cache after boot to prove the present-env path).
 *
 * Registered via EventService::addListener(..., '@' . self::class) — the '@serviceId' form
 * resolves this service lazily and invokes it as a callable, so the entry point is
 * __invoke(object $event). Idempotent + re-drivable: purging an already-fresh tag is a no-op,
 * so `thallo:resync` can safely re-run it.
 */
final class PurgeCdnListener
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

        $edge = $this->edgeCache();
        // NullEdgeCache (no glueful/cdn) reports disabled: clean skip, never purge.
        if (!$edge->isEnabled()) {
            return;
        }

        foreach ($tags as $tag) {
            $edge->purgeByTag($tag);
        }
    }

    private function edgeCache(): EdgeCacheInterface
    {
        /** @var EdgeCacheInterface $edge */
        $edge = $this->container->get(EdgeCacheInterface::class);
        return $edge;
    }

    /**
     * Map an event to the surrogate keys it purges (identical to the cache listener's set).
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
     * Resolve a content-type UUID to its slug (memoised). Returns null when the type can no
     * longer be found (e.g. a deleted type) — the entry tag is still purged.
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

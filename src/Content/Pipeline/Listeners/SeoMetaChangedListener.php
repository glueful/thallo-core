<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Pipeline\Listeners;

use Glueful\Cache\CacheStore;
use Glueful\Cache\Contracts\EdgeCacheInterface;
use Psr\Container\ContainerInterface;
use Thallo\Contracts\Seo\SeoMetaChanged;

/**
 * SEO override changed → the entry's cached rendered pages are stale in BOTH cache
 * layers (seo-head spec §5). The existing content listeners read content-event shapes
 * and would ignore SeoMetaChanged, so this dedicated listener does both halves itself:
 * drops the internal page-cache tag and edge-purges the same surrogate tag with
 * PurgeCdnListener's exact disabled-skip discipline. Deliberately NO type-level tags —
 * a meta edit changes one entry's pages only.
 *
 * Both services are resolved from the container per-invocation rather than captured in
 * the constructor: this listener is a long-lived singleton registered at boot, so
 * resolving lazily means it always uses the current bindings (and keeps the wiring
 * testable by allowing either cache to be substituted after boot).
 *
 * Registered via EventService::addListener(..., '@' . self::class) — the '@serviceId'
 * form resolves this service lazily and invokes it as a callable, so the entry point is
 * __invoke(object $event). Idempotent: purging an already-clear tag is a no-op.
 */
final class SeoMetaChangedListener
{
    public function __construct(private readonly ContainerInterface $container)
    {
    }

    public function __invoke(object $event): void
    {
        if (!$event instanceof SeoMetaChanged) {
            return;
        }
        $tag = 'thallo:entry:' . $event->entryUuid;

        $this->cache()->invalidateTags([$tag]);

        $edge = $this->edgeCache();
        // NullEdgeCache (no glueful/cdn) reports disabled: clean skip, never purge.
        if (!$edge->isEnabled()) {
            return;
        }
        $edge->purgeByTag($tag);
    }

    private function cache(): CacheStore
    {
        /** @var CacheStore $store */
        $store = $this->container->get(CacheStore::class);
        return $store;
    }

    private function edgeCache(): EdgeCacheInterface
    {
        /** @var EdgeCacheInterface $edge */
        $edge = $this->container->get(EdgeCacheInterface::class);
        return $edge;
    }
}

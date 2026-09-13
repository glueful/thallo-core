<?php

declare(strict_types=1);

namespace Thallo\Core\Setup;

use Glueful\Cache\CacheStore;
use Glueful\Routing\RouteCache;

/**
 * What outlives a release and must not: the compiled route table (its signature covers the
 * app's and the framework's route files, not the routes shipped in vendor/, so after
 * `composer update` a stale table keeps serving the previous release's routes) and the rendered
 * page cache. Provision drops both, so `composer update && php glueful thallo:provision` is the
 * whole upgrade; `route:cache:clear` and `render:cache:clear` remain for a deploy that skips it.
 */
final class UpgradeCaches
{
    public function __construct(
        private readonly RouteCache $routes,
        private readonly CacheStore $cache,
    ) {
    }

    /** @return list<string> what was dropped, for the operator's eyes */
    public function clear(): array
    {
        $this->routes->clear();
        $this->cache->deletePattern('render:*');
        $this->cache->deletePattern('tenant:*:render:*');

        return ['route table', 'rendered pages'];
    }
}

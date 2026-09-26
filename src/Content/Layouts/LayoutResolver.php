<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Layouts;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Thallo\Contracts\Layouts\LayoutReader;
use Thallo\Tenancy\Cache\TenantCacheSegment;

/**
 * The saved layouts, as the render reads them (type layouts spec §7.4): one cached answer per
 * subject — the layout, or "none" — so the table is read once per page kind, not once per page.
 * Every writer calls `forget()` from an after-commit callback, so a first layout, a new version or
 * a removal is found on the next render rather than after the cache expires.
 */
final class LayoutResolver implements LayoutReader
{
    private const TTL = 3600;

    /** @param CacheStore<mixed> $cache */
    public function __construct(
        private readonly LayoutRepository $layouts,
        private readonly CacheStore $cache,
        private readonly ?TenantCacheSegment $tenantCache = null,
        private readonly ?ApplicationContext $context = null,
    ) {
    }

    public function for(string $surface, string $target): ?array
    {
        $key = $this->key($surface, $target);
        $cached = $this->cache->get($key);
        if (is_array($cached) && ($cached['none'] ?? false) === true) {
            return null;
        }
        if (is_array($cached) && is_array($cached['layout'] ?? null)) {
            return $cached['layout'];
        }
        $row = $this->layouts->find($surface, $target);
        $layout = $row === null || $row['blocks'] === null ? null : [
            'blocks' => $row['blocks'],
            'settings' => $row['settings'],
            'lock_version' => $row['lock_version'],
        ];
        $this->cache->set($key, $layout === null ? ['none' => true] : ['layout' => $layout], self::TTL);
        return $layout;
    }

    public function forget(string $surface, string $target): void
    {
        $this->cache->delete($this->key($surface, $target));
    }

    private function key(string $surface, string $target): string
    {
        $prefix = $this->tenantCache !== null && $this->context !== null
            ? $this->tenantCache->segment($this->context, 'layouts')
            : '';
        return $prefix . 'thallo:layout:' . $surface . ':' . $target;
    }
}

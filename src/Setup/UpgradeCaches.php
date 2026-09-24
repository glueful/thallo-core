<?php

declare(strict_types=1);

namespace Thallo\Core\Setup;

use Glueful\Cache\CacheStore;
use Glueful\Routing\RouteCache;

/**
 * What outlives a release and must not: the compiled route table (its signature covers the
 * app's and the framework's route files, not the routes shipped in vendor/, so after
 * `composer update` a stale table keeps serving the previous release's routes), the rendered
 * page cache, and the compiled templates. Twig reuses a compiled template while the template file
 * is not newer than it, and a release archive stamps every file with its commit's time — in the
 * past — so a template compiled on the old install after that moment kept serving the old markup.
 * Provision drops all three, so `composer update && php glueful thallo:provision` is the whole
 * upgrade; `route:cache:clear` and `render:cache:clear` remain for a deploy that skips it.
 */
final class UpgradeCaches
{
    public function __construct(
        private readonly RouteCache $routes,
        private readonly CacheStore $cache,
        /** The compiled-template directory (storage/cache/twig); null leaves it alone. */
        private readonly ?string $compiledTemplates = null,
    ) {
    }

    /** @return list<string> what was dropped, for the operator's eyes */
    public function clear(): array
    {
        $this->routes->clear();
        $this->cache->deletePattern('render:*');
        $this->cache->deletePattern('tenant:*:render:*');
        $cleared = ['route table', 'rendered pages'];
        if ($this->compiledTemplates !== null && is_dir($this->compiledTemplates)) {
            self::emptyDirectory($this->compiledTemplates);
            $cleared[] = 'compiled templates';
        }

        return $cleared;
    }

    /** Removes what is inside $dir, keeping $dir itself for the next compile. */
    private static function emptyDirectory(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $path = $item->getPathname();
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
    }
}

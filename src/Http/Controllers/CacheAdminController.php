<?php

declare(strict_types=1);

namespace Thallo\Core\Http\Controllers;

use Thallo\Core\Http\DTOs\ClearCacheData;
use Thallo\Core\Http\DTOs\Responses\CacheStatusResultData;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;

/**
 * Cache status + clear operations for the admin Utilities › Cache page.
 *
 * Wraps the framework {@see CacheStore} (driver, stats, key count, flush, tag invalidation). Thallo
 * tags delivery cache by `thallo:type:<slug>` and `thallo:entry:<uuid>`, so a per-content-type clear
 * is a targeted tag invalidation; an empty clear flushes everything. Gated by `system.access`.
 */
final class CacheAdminController
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    /** GET /v1/admin/cache */
    #[ApiOperation(
        summary: 'Cache status',
        description: 'Cache driver, prefix, tag support, key count and driver stats. Requires '
            . '`system.access`.',
        tags: ['Utilities'],
    )]
    #[ApiResponse(200, schema: CacheStatusResultData::class, description: 'Cache status.')]
    public function show(): Response
    {
        return Response::success(['cache' => $this->status()], 'Cache status retrieved.');
    }

    /** POST /v1/admin/cache/clear */
    #[ApiOperation(
        summary: 'Clear cache',
        description: 'Clears the cache. With `content_type`, only that type\'s delivery cache '
            . '(the `thallo:type:<slug>` tag) is invalidated; otherwise the whole cache is flushed. '
            . 'Requires `system.access`.',
        tags: ['Utilities'],
    )]
    #[ApiResponse(200, schema: CacheStatusResultData::class, description: 'Cleared; returns fresh status.')]
    public function clear(ClearCacheData $input): Response
    {
        $cache = $this->cache();
        $type = $input->content_type !== null ? trim($input->content_type) : '';

        if ($type !== '') {
            $cache->invalidateTags(['thallo:type:' . $type]);
            $message = "Cleared the delivery cache for content type “{$type}”.";
        } else {
            $cache->flush();
            $message = 'All cache cleared.';
        }

        return Response::success(['cache' => $this->status()], $message);
    }

    private function cache(): CacheStore
    {
        return app($this->context, CacheStore::class);
    }

    /** @return array<string,mixed> */
    private function status(): array
    {
        $cache = $this->cache();

        $stats = [];
        try {
            foreach ($cache->getStats() as $key => $value) {
                $stats[] = ['key' => (string) $key, 'value' => $this->scalar($value)];
            }
        } catch (\Throwable) {
            // Some drivers don't expose stats — show none rather than failing the page.
        }

        $keyCount = 0;
        try {
            $keyCount = $cache->getKeyCount();
        } catch (\Throwable) {
            // pattern key counting is best-effort.
        }

        return [
            'driver' => (string) config($this->context, 'cache.default', 'file'),
            'prefix' => (string) config($this->context, 'cache.prefix', ''),
            'tags_enabled' => (bool) config($this->context, 'cache.enable_tags', true),
            'key_count' => $keyCount,
            'stats' => $stats,
        ];
    }

    private function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_scalar($value)) {
            return (string) $value;
        }

        return json_encode($value) ?: '';
    }
}

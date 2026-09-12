<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Preview;

use Glueful\Cache\CacheStore;
use Glueful\Bootstrap\ApplicationContext;
use Thallo\Tenancy\Cache\TenantCacheSegment;

/**
 * The visual canvas's ephemeral working copy (loop C spec §3): the VALIDATED,
 * CLEANED fields of one entry+locale, stashed in cache so /_preview/{token}
 * can render unsaved work. Never persisted; TTL-bounded; overwritten per
 * apply; cleared by a successful saveDraft. Keyed by {entry, locale} — NOT by
 * token — so the save path (which never sees the token) can clear it.
 */
final class PreviewWorkingCopyStore
{
    /** @param CacheStore<mixed> $cache */
    public function __construct(
        private readonly CacheStore $cache,
        private readonly ?TenantCacheSegment $tenantCache = null,
        private readonly ?ApplicationContext $context = null,
    ) {
    }

    private function key(string $entryUuid, string $locale): string
    {
        $prefix = $this->tenantCache !== null && $this->context !== null
            ? $this->tenantCache->segment($this->context, 'preview')
            : '';

        return $prefix . 'thallo:preview:working:' . $entryUuid . ':' . $locale;
    }

    /** @param array<string,mixed> $cleanFields validator OUTPUT only — never raw payload */
    public function put(string $entryUuid, string $locale, array $cleanFields, int $ttl): void
    {
        $this->cache->set($this->key($entryUuid, $locale), $cleanFields, $ttl);
    }

    /** @return array<string,mixed>|null */
    public function get(string $entryUuid, string $locale): ?array
    {
        $value = $this->cache->get($this->key($entryUuid, $locale));
        return is_array($value) ? $value : null;
    }

    public function clear(string $entryUuid, string $locale): void
    {
        $this->cache->delete($this->key($entryUuid, $locale));
    }
}

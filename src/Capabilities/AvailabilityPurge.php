<?php

declare(strict_types=1);

namespace Thallo\Core\Capabilities;

use Glueful\Cache\CacheStore;
use Glueful\Cache\Contracts\EdgeCacheInterface;
use Glueful\Database\Connection;
use Thallo\Contracts\Capability\AvailabilityFingerprint;
use Thallo\Contracts\Delivery\RenderedPageCachePurge;
use Thallo\Contracts\Settings\SystemChannel;

/**
 * Purges cached pages when the features in use change (search block spec §3.6). The origin is
 * already protected the moment the state changes: page keys carry the availability fingerprint,
 * so no request reads a page rendered under the old state. This class frees that space and
 * clears the CDN, durably.
 *
 * The required purges — the page tag, then the edge — come first; only once both succeed does the
 * marker advance, in one transaction with a retry obligation named by the new fingerprint. The
 * retry exists because an obsolete response may still reach the CDN after the first purge; it is a
 * retry, not a bound on staleness (the bound is the pages' `max-age=0, must-revalidate`). An older
 * completion clears only the exact obligation it read, so it can never erase a newer one.
 */
final class AvailabilityPurge
{
    public const MARKER = 'render.availability.purged';
    /** Value: "{fingerprint}|{due, UTC Y-m-d H:i:s}". */
    public const EDGE_DUE = 'render.availability.edge_purge_due';

    public function __construct(
        private readonly AvailabilityFingerprint $fingerprint,
        private readonly SystemChannel $system,
        private readonly Connection $db,
        private readonly CacheStore $cache,
        private readonly int $graceSeconds,
        private readonly ?RenderedPageCachePurge $pages = null,
        private readonly ?EdgeCacheInterface $edge = null,
    ) {
    }

    public function reconcile(): void
    {
        $current = $this->fingerprint->current();
        $last = $this->system->get(self::MARKER);
        if ($last === $current) {
            return;
        }
        if ($last === null) {
            // First sight: nothing was cached under an older recorded state.
            $this->system->put(self::MARKER, $current);
            return;
        }

        if ($this->pages !== null) {
            $this->pages->purge(['thallo:render:page']);
        } else {
            $this->cache->invalidateTags(['thallo:render:page']);
        }
        $edgeOn = $this->edge !== null && $this->edge->isEnabled();
        if ($edgeOn && !$this->edge->purgeAll()) {
            return; // the marker stays: the next request or tick repeats both purges
        }

        $this->db->transaction(function () use ($current, $edgeOn): void {
            if ($edgeOn) {
                $due = gmdate('Y-m-d H:i:s', time() + $this->graceSeconds);
                $this->system->put(self::EDGE_DUE, $current . '|' . $due);
            }
            $this->system->put(self::MARKER, $current);
        });
    }

    /** The retry. It clears only the obligation it read, and only if that is still unchanged. */
    public function completeDue(\DateTimeImmutable $now): void
    {
        $value = $this->system->get(self::EDGE_DUE);
        if ($value === null || !str_contains($value, '|')) {
            return;
        }
        [, $due] = explode('|', $value, 2);
        if ($now < new \DateTimeImmutable($due, new \DateTimeZone('UTC'))) {
            return;
        }
        if ($this->edge !== null && $this->edge->isEnabled() && !$this->edge->purgeAll()) {
            return; // still due; the next tick retries
        }
        $this->db->table('thallo_system_flags')
            ->where('key', '=', self::EDGE_DUE)
            ->where('value', '=', $value)
            ->delete();
        if (method_exists($this->system, 'clearCache')) {
            $this->system->clearCache();
        }
    }
}

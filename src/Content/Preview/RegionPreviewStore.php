<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Preview;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Thallo\Tenancy\Cache\TenantCacheSegment;

/**
 * A regions-stage session's two records (regions-stage spec §4.2), both keyed by the token's
 * session id and both living exactly until the token's absolute expiry:
 *
 * - the **baseline** — both regions `{blocks, settings, lock_version}` as minted, or as of this
 *   session's last save; what the stage shows with no working copy;
 * - the **working copy** — the accepted `{header, footer}` document, built by compare-and-set
 *   applies exactly as {@see PreviewWorkingCopyStore} builds an entry's, but with no 300-second
 *   cap: an idle editor's accepted edits stay on the stage while the session is valid.
 *
 * Every record carries its `exp`; a record read after it is absent, whatever the cache still
 * holds. The clock is injectable so that lifetime can be proven.
 */
final class RegionPreviewStore
{
    private const LOCK_SPINS = 40;
    private const LOCK_SPIN_US = 5_000;
    private const LOCK_STALE_SECONDS = 5;

    /** @var \Closure(): int */
    private readonly \Closure $now;

    /** @param CacheStore<mixed> $cache */
    public function __construct(
        private readonly CacheStore $cache,
        private readonly ?TenantCacheSegment $tenantCache = null,
        private readonly ?ApplicationContext $context = null,
        ?\Closure $now = null,
    ) {
        $this->now = $now ?? static fn (): int => time();
    }

    /** @param array{header: array<string,mixed>, footer: array<string,mixed>} $regions */
    public function putBaseline(string $session, array $regions, int $expiresAt): void
    {
        $this->cache->set(
            $this->key('baseline', $session),
            ['regions' => $regions, 'exp' => $expiresAt],
            $this->ttl($expiresAt),
        );
    }

    /** @return array{header: array<string,mixed>, footer: array<string,mixed>}|null */
    public function baseline(string $session): ?array
    {
        $value = $this->cache->get($this->key('baseline', $session));
        if (!is_array($value) || !is_array($value['regions'] ?? null) || $this->expired($value)) {
            return null;
        }
        return $value['regions'];
    }

    /**
     * Compare-and-set acceptance into the session's working copy — the result shape of
     * {@see PreviewWorkingCopyStore::accept()}.
     *
     * @param array<string,mixed> $fields validator OUTPUT only
     * @param list<array<string,mixed>> $ops
     * @return array{accepted: bool, epoch: ?string, revision: ?int, baseline: ?int, accepted_at: ?string}
     */
    public function accept(
        string $session,
        ?string $epoch,
        ?int $baseRevision,
        array $fields,
        array $ops,
        int $expiresAt,
    ): array {
        $key = $this->key('working', $session);
        return $this->locked($key, function () use ($key, $epoch, $baseRevision, $fields, $ops, $expiresAt): array {
            $record = $this->read($key);
            $current = $record === null ? [null, null] : [$record['epoch'], $record['revision']];
            if ([$epoch, $baseRevision] !== $current) {
                return [
                    'accepted' => false,
                    'epoch' => $current[0],
                    'revision' => $current[1],
                    'baseline' => null,
                    'accepted_at' => null,
                ];
            }
            $next = [
                'epoch' => $record['epoch'] ?? PreviewWorkingCopyStore::ulid(),
                'revision' => ($record['revision'] ?? 0) + 1,
                'fields' => $fields,
                'ops' => $ops,
                'accepted_at' => date('c'),
                'exp' => $expiresAt,
            ];
            $this->cache->set($key, $next, $this->ttl($expiresAt));
            return [
                'accepted' => true,
                'epoch' => $next['epoch'],
                'revision' => $next['revision'],
                'baseline' => $record['revision'] ?? 0,
                'accepted_at' => $next['accepted_at'],
            ];
        });
    }

    /** @return array{epoch: string, revision: int, fields: array<string,mixed>, ops: list<array<string,mixed>>, accepted_at: string, exp: int}|null */
    public function current(string $session): ?array
    {
        return $this->read($this->key('working', $session));
    }

    /**
     * What one render shows, read once (regions-stage spec §4.4): the working copy if there is
     * one, else the baseline, else null (the session expired).
     *
     * @return array{source: 'working'|'baseline', regions: array<string,mixed>, epoch: ?string, revision: ?int}|null
     */
    public function snapshot(string $session): ?array
    {
        $working = $this->current($session);
        if ($working !== null) {
            return [
                'source' => 'working',
                'regions' => $working['fields'],
                'epoch' => $working['epoch'],
                'revision' => $working['revision'],
            ];
        }
        $baseline = $this->baseline($session);
        if ($baseline === null) {
            return null;
        }
        return ['source' => 'baseline', 'regions' => $baseline, 'epoch' => null, 'revision' => null];
    }

    /**
     * Clear the working copy only when it still holds exactly this pair (regions-stage spec §4.5):
     * a delayed save from another epoch at the same revision number clears nothing.
     */
    public function clearIfPair(string $session, string $epoch, int $revision): bool
    {
        $key = $this->key('working', $session);
        return $this->locked($key, function () use ($key, $epoch, $revision): bool {
            $record = $this->read($key);
            if ($record === null || $record['epoch'] !== $epoch || $record['revision'] !== $revision) {
                return false;
            }
            $this->cache->delete($key);
            return true;
        });
    }

    private function key(string $record, string $session): string
    {
        $prefix = $this->tenantCache !== null && $this->context !== null
            ? $this->tenantCache->segment($this->context, 'preview')
            : '';
        return $prefix . ($record === 'baseline'
            ? 'thallo:preview:regions:baseline:'
            : 'thallo:preview:working:regions:') . $session;
    }

    private function ttl(int $expiresAt): int
    {
        return max(1, $expiresAt - ($this->now)());
    }

    /** @param array<string,mixed> $value */
    private function expired(array $value): bool
    {
        return !is_int($value['exp'] ?? null) || ($this->now)() > $value['exp'];
    }

    /** @return array{epoch: string, revision: int, fields: array<string,mixed>, ops: list<array<string,mixed>>, accepted_at: string, exp: int}|null */
    private function read(string $key): ?array
    {
        $value = $this->cache->get($key);
        if (
            !is_array($value)
            || !is_string($value['epoch'] ?? null)
            || !is_int($value['revision'] ?? null)
            || $this->expired($value)
        ) {
            return null;
        }
        return [
            'epoch' => $value['epoch'],
            'revision' => $value['revision'],
            'fields' => is_array($value['fields'] ?? null) ? $value['fields'] : [],
            'ops' => is_array($value['ops'] ?? null) ? array_values($value['ops']) : [],
            'accepted_at' => (string) ($value['accepted_at'] ?? ''),
            'exp' => $value['exp'],
        ];
    }

    /**
     * The same short mutual exclusion {@see PreviewWorkingCopyStore} uses over one record.
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function locked(string $key, callable $fn): mixed
    {
        $lock = $key . ':lock';
        for ($spin = 0; $spin < self::LOCK_SPINS; $spin++) {
            if ($this->cache->increment($lock) === 1) {
                $this->cache->set($lock . ':at', time(), self::LOCK_STALE_SECONDS * 2);
                try {
                    return $fn();
                } finally {
                    $this->cache->delete($lock);
                    $this->cache->delete($lock . ':at');
                }
            }
            $at = $this->cache->get($lock . ':at');
            if (!is_int($at) || $at < time() - self::LOCK_STALE_SECONDS) {
                $this->cache->delete($lock);
                continue;
            }
            usleep(self::LOCK_SPIN_US);
        }
        throw new \RuntimeException('region preview working copy is locked');
    }
}

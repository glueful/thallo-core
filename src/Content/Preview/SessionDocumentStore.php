<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Preview;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Thallo\Tenancy\Cache\TenantCacheSegment;

/**
 * A stage session's two records (regions-stage spec §4.2, type layouts spec §5.3), both keyed by
 * the token's session id and both living exactly until the token's absolute expiry:
 *
 * - the **baseline** — the document as minted, or as of this session's last save; what the stage
 *   shows with no working copy;
 * - the **working copy** — the accepted document, built by compare-and-set applies exactly as
 *   {@see PreviewWorkingCopyStore} builds an entry's, but with no 300-second cap: an idle editor's
 *   accepted edits stay on the stage while the session is valid.
 *
 * Keys are `{tenant}thallo:preview:{namespace}:baseline:{s}` and
 * `{tenant}thallo:preview:working:{namespace}:{s}`. Every record carries its `exp`; a record read
 * after it is absent, whatever the cache still holds. The clock is injectable so that lifetime can
 * be proven.
 */
abstract class SessionDocumentStore
{
    protected const LOCK_SPINS = 40;
    protected const LOCK_SPIN_US = 5_000;
    protected const LOCK_STALE_SECONDS = 5;

    /** @var \Closure(): int */
    protected readonly \Closure $now;

    /** @param CacheStore<mixed> $cache */
    public function __construct(
        protected readonly CacheStore $cache,
        private readonly ?TenantCacheSegment $tenantCache = null,
        private readonly ?ApplicationContext $context = null,
        ?\Closure $now = null,
    ) {
        $this->now = $now ?? static fn (): int => time();
    }

    /** The records' namespace: `regions`, `layout`. It also names the baseline's document key. */
    abstract protected function namespace(): string;

    /** @param array<string,mixed> $document */
    public function putBaseline(string $session, array $document, int $expiresAt): void
    {
        $this->cache->set(
            $this->key('baseline', $session),
            [$this->namespace() => $document, 'exp' => $expiresAt],
            $this->ttl($expiresAt),
        );
    }

    /** @return array<string,mixed>|null */
    public function baseline(string $session): ?array
    {
        $value = $this->cache->get($this->key('baseline', $session));
        if (!is_array($value) || !is_array($value[$this->namespace()] ?? null) || $this->expired($value)) {
            return null;
        }
        return $value[$this->namespace()];
    }

    /**
     * Whether this session refuses every apply (a removed layout's session, type layouts spec §5.5).
     * Asked inside the working copy's lock, so a retirement and an apply never interleave.
     */
    protected function refuses(string $session): bool
    {
        return false;
    }

    /**
     * Compare-and-set acceptance into the session's working copy — the result shape of
     * {@see PreviewWorkingCopyStore::accept()}.
     *
     * @param array<string,mixed> $fields validator OUTPUT only
     * @param list<array<string,mixed>> $ops
     * @return array{accepted: bool, epoch: ?string, revision: ?int, baseline: ?int, accepted_at: ?string,
     *     retired?: true}
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
        $accept = function () use ($session, $key, $epoch, $baseRevision, $fields, $ops, $expiresAt): array {
            if ($this->refuses($session)) {
                return [
                    'accepted' => false,
                    'epoch' => null,
                    'revision' => null,
                    'baseline' => null,
                    'accepted_at' => null,
                    'retired' => true,
                ];
            }
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
        };
        return $this->locked($key, $accept);
    }

    /** @return array{epoch: string, revision: int, fields: array<string,mixed>, ops: list<array<string,mixed>>, accepted_at: string, exp: int}|null */
    public function current(string $session): ?array
    {
        return $this->read($this->key('working', $session));
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

    protected function key(string $record, string $session): string
    {
        $prefix = $this->tenantCache !== null && $this->context !== null
            ? $this->tenantCache->segment($this->context, 'preview')
            : '';
        $namespace = $this->namespace();
        return $prefix . ($record === 'baseline'
            ? "thallo:preview:{$namespace}:baseline:"
            : "thallo:preview:working:{$namespace}:") . $session;
    }

    protected function ttl(int $expiresAt): int
    {
        return max(1, $expiresAt - ($this->now)());
    }

    /** @param array<string,mixed> $value */
    protected function expired(array $value): bool
    {
        return !is_int($value['exp'] ?? null) || ($this->now)() > $value['exp'];
    }

    /** @return array{epoch: string, revision: int, fields: array<string,mixed>, ops: list<array<string,mixed>>, accepted_at: string, exp: int}|null */
    protected function read(string $key): ?array
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
    protected function locked(string $key, callable $fn): mixed
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
        throw new \RuntimeException($this->namespace() . ' preview working copy is locked');
    }
}

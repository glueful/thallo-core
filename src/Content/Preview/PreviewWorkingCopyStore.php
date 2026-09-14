<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Preview;

use Glueful\Cache\CacheStore;
use Glueful\Bootstrap\ApplicationContext;
use Thallo\Tenancy\Cache\TenantCacheSegment;

/**
 * The visual canvas's working copy (visual builder spec §3.5): one record per entry+locale in
 * the cache — `{epoch, revision, fields, ops, accepted_at}` — holding the VALIDATED, CLEANED
 * fields so /_preview/{token} renders unsaved work. Never persisted; TTL-bounded.
 *
 * Acceptance is compare-and-set under a lock: the request's `(epoch, base_revision)` must
 * equal the stored pair exactly, else it is stale and the caller learns the current pair. A
 * null pair initialises only when no record exists, minting a fresh epoch (a ULID) at revision
 * 1. Save and expiry end an epoch: a save clears the record only when the record's revision
 * equals the revision the save was submitted from, so an older save never discards a newer
 * accepted copy; the next apply after a save or an expiry starts a new epoch. Keyed by
 * {entry, locale} — not by token — so the save path can clear it.
 */
final class PreviewWorkingCopyStore
{
    private const LOCK_SPINS = 40;
    private const LOCK_SPIN_US = 5000;
    private const LOCK_STALE_SECONDS = 5;

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

    /**
     * Compare-and-set acceptance.
     *
     * @param array<string,mixed> $cleanFields validator OUTPUT only — never raw payload
     * @param list<array<string,mixed>> $ops the committed transaction's sanitised operations
     * @return array{accepted: bool, epoch: ?string, revision: ?int, baseline: ?int, accepted_at: ?string}
     *         when not accepted, `epoch`/`revision` are the CURRENT pair (null = no record)
     */
    public function accept(
        string $entryUuid,
        string $locale,
        ?string $epoch,
        ?int $baseRevision,
        array $cleanFields,
        array $ops,
        int $ttl,
    ): array {
        $key = $this->key($entryUuid, $locale);
        return $this->locked($key, function () use ($key, $epoch, $baseRevision, $cleanFields, $ops, $ttl): array {
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
                'epoch' => $record['epoch'] ?? self::ulid(),
                'revision' => ($record['revision'] ?? 0) + 1,
                'fields' => $cleanFields,
                'ops' => $ops,
                'accepted_at' => date('c'),
            ];
            $this->cache->set($key, $next, $ttl);
            return [
                'accepted' => true,
                'epoch' => $next['epoch'],
                'revision' => $next['revision'],
                'baseline' => $record['revision'] ?? 0,
                'accepted_at' => $next['accepted_at'],
            ];
        });
    }

    /** @return array{epoch: string, revision: int, fields: array<string,mixed>, ops: list<array<string,mixed>>, accepted_at: string}|null */
    public function record(string $entryUuid, string $locale): ?array
    {
        return $this->read($this->key($entryUuid, $locale));
    }

    /** The accepted pair, or null when no record exists. @return array{epoch: string, revision: int}|null */
    public function current(string $entryUuid, string $locale): ?array
    {
        $record = $this->record($entryUuid, $locale);
        return $record === null ? null : ['epoch' => $record['epoch'], 'revision' => $record['revision']];
    }

    /** @return array<string,mixed>|null the accepted fields (what a preview renders) */
    public function fields(string $entryUuid, string $locale): ?array
    {
        return $this->record($entryUuid, $locale)['fields'] ?? null;
    }

    /**
     * Clear the record if it is still at `$revision` (a save submitted from that revision);
     * null clears unconditionally (a save that never took part in the protocol).
     *
     * @return bool whether a record was cleared
     */
    public function clearIfRevision(string $entryUuid, string $locale, ?int $revision): bool
    {
        $key = $this->key($entryUuid, $locale);
        return $this->locked($key, function () use ($key, $revision): bool {
            $record = $this->read($key);
            if ($record === null || ($revision !== null && $record['revision'] !== $revision)) {
                return false;
            }
            $this->cache->delete($key);
            return true;
        });
    }

    public function clear(string $entryUuid, string $locale): void
    {
        $this->cache->delete($this->key($entryUuid, $locale));
    }

    /** @return array{epoch: string, revision: int, fields: array<string,mixed>, ops: list<array<string,mixed>>, accepted_at: string}|null */
    private function read(string $key): ?array
    {
        $value = $this->cache->get($key);
        if (!is_array($value) || !is_string($value['epoch'] ?? null) || !is_int($value['revision'] ?? null)) {
            return null;
        }
        return [
            'epoch' => $value['epoch'],
            'revision' => $value['revision'],
            'fields' => is_array($value['fields'] ?? null) ? $value['fields'] : [],
            'ops' => is_array($value['ops'] ?? null) ? array_values($value['ops']) : [],
            'accepted_at' => (string) ($value['accepted_at'] ?? ''),
        ];
    }

    /**
     * A short mutual exclusion over one record: the cache's atomic increment is the latch, a
     * stamp bounds a holder that died, and the holder always releases.
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
        throw new \RuntimeException('preview working copy is locked');
    }

    /** A ULID: 48-bit millisecond time then 80 random bits, Crockford base32, 26 characters. */
    private static function ulid(): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $out = '';
        $time = (int) floor(microtime(true) * 1000);
        for ($i = 9; $i >= 0; $i--) {
            $out = $alphabet[$time % 32] . $out;
            $time = intdiv($time, 32);
        }
        $random = random_bytes(10);
        $bits = '';
        foreach (str_split($random) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        for ($i = 0; $i < 16; $i++) {
            $out .= $alphabet[bindec(substr($bits, $i * 5, 5))];
        }
        return $out;
    }
}

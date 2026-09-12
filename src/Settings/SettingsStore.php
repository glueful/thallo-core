<?php

declare(strict_types=1);

namespace Thallo\Core\Settings;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Contracts\Settings\SystemChannel;

/**
 * Thin key/value store over the `settings` table — the runtime-mutable instance settings
 * (set at install by {@see \Thallo\Core\Setup\SetupService} and edited from Settings › General).
 *
 * Unlike `.env`, rows are shared across every app instance and apply on the next request with no
 * restart. Rows are loaded once per instance (the service is container-shared, so once per request)
 * and memoized; writes invalidate the cache.
 *
 * System keys (see {@see SystemKeys}) are NOT stored here — reads and writes of those keys are routed
 * to the unscoped {@see SystemChannel}, so enabling multi-tenancy cannot fragment or lose them.
 */
final class SettingsStore
{
    /** @var array<string,string>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly SystemChannel $system,
    ) {
    }

    /** @return array<string,string> all rows, key => value */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $out = [];
        foreach (db($this->context)->table('settings')->get() as $row) {
            $key = (string) ($row['key'] ?? '');
            // Defensive filter: a system-classified key should never physically be in
            // `settings` (writes never land here — see putMany()/forget()), but a stray or
            // legacy row must still never leak into the tenant map (spec §2).
            if ($key !== '' && !SystemKeys::isSystem($key)) {
                $out[$key] = (string) ($row['value'] ?? '');
            }
        }

        return $this->cache = $out;
    }

    public function get(string $key): ?string
    {
        if (SystemKeys::isSystem($key)) {
            return $this->system->get($key);
        }

        return $this->all()[$key] ?? null;
    }

    /**
     * Remove a stored override so the config/.env fallback shows through
     * (homepage-setting spec §0: clearing must DELETE the row — an empty
     * string row would shadow the fallback with '').
     */
    public function forget(string $key): void
    {
        if (SystemKeys::isSystem($key)) {
            $this->system->forget($key);
            return;
        }

        db($this->context)->table('settings')->where(['key' => $key])->delete();
        $this->cache = null;
    }

    /**
     * Drop the memo so the next read hits the database. Writes through this
     * store invalidate automatically; this exists for callers that mutate
     * `settings` AROUND the store (the test harness truncates tables
     * between tests while the container singleton lives on).
     */
    public function clearCache(): void
    {
        $this->cache = null;
    }

    /**
     * Upsert each pair into `settings`.
     *
     * @param array<string,string> $pairs
     */
    public function putMany(array $pairs): void
    {
        if ($pairs === []) {
            return;
        }

        $now = date('Y-m-d H:i:s');
        foreach ($pairs as $key => $value) {
            // System keys never touch `settings` — they belong to the unscoped channel.
            if (SystemKeys::isSystem($key)) {
                $this->system->put($key, $value);
                continue;
            }
            // `key` is the (non-integer) primary key, so upsert is check-then-write (mirrors SetupService).
            $existing = db($this->context)->table('settings')->where(['key' => $key])->first();
            if ($existing === null) {
                db($this->context)->table('settings')->insert([
                    'key' => $key,
                    'value' => $value,
                    'updated_at' => $now,
                ]);
            } else {
                db($this->context)->table('settings')->where(['key' => $key])->update([
                    'value' => $value,
                    'updated_at' => $now,
                ]);
            }
        }

        $this->cache = null;
    }
}

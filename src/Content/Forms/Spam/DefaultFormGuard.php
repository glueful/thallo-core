<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Forms\Spam;

use Thallo\Core\Content\Forms\FormDescriptor;
use Glueful\Cache\CacheStore;
use Symfony\Component\HttpFoundation\Request;

/**
 * The v1 guard chain (form-block spec §8): honeypot → time-trap → rate-limit, in that
 * order so a rejected bot never consumes the rate budget. Every trap reads its parameters
 * from the SEALED descriptor (honeypot field name, minSeconds) — a bot cannot see or
 * forge them because they live inside the encrypted token, not the visible markup.
 */
final class DefaultFormGuard implements FormSubmissionGuard
{
    public function __construct(
        private readonly CacheStore $cache,
        private readonly int $rateMax,
        private readonly int $rateWindow,
    ) {
    }

    public function check(Request $request, FormDescriptor $descriptor): GuardResult
    {
        // 1) Honeypot: the sealed field must arrive empty. A filled value is a bot.
        $trap = $request->request->get($descriptor->honeypotField);
        if (is_string($trap) && trim($trap) !== '') {
            return GuardResult::reject('honeypot');
        }

        // 2) Time-trap: a human takes at least minSeconds between render and submit.
        $stamp = (int) $request->request->get('_t');
        if ($stamp > 0 && (time() - $stamp) < $descriptor->minSeconds) {
            return GuardResult::reject('time_trap');
        }

        // 3) Rate-limit per form_key + client IP over a rolling window.
        $ip = (string) ($request->getClientIp() ?? 'unknown');
        // md5 the IP so IPv6 colons never trip a cache driver's key validator.
        $key = 'forms:rate:' . $descriptor->formKey . ':' . md5($ip);
        $count = $this->cache->increment($key, 1);
        if ($count <= 1) {
            $this->cache->set($key, $count, $this->rateWindow); // arm the window TTL on first hit
        }
        if ($count > $this->rateMax) {
            return GuardResult::reject('rate_limit');
        }

        return GuardResult::pass();
    }
}

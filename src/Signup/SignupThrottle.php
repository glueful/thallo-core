<?php

declare(strict_types=1);

namespace Thallo\Core\Signup;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;

final class SignupThrottle
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly Connection $connection,
    ) {
    }

    public function allowIntent(string $capability, string $ip, string $email): bool
    {
        $window = max(60, (int) config($this->context, 'signup.limits.window_seconds', 3600));
        if (!$this->consumeWindow('intent_ip', $ip, $window, $this->limit('per_ip'))) {
            return false;
        }
        if (!$this->consumeWindow('intent_email', strtolower(trim($email)), $window, $this->limit('per_email'))) {
            return false;
        }
        return $this->consumeDailyCap($capability);
    }

    public function allowResend(string $intentUuid, string $email, string $ip): bool
    {
        $window = max(60, (int) config($this->context, 'signup.limits.window_seconds', 3600));
        $limit = $this->limit('resend_per_intent');
        return $this->consumeWindow('resend_intent', $intentUuid, $window, $limit)
            && $this->consumeWindow('resend_email', strtolower(trim($email)), $window, $limit)
            && $this->consumeWindow('resend_ip', $ip, $window, $limit);
    }

    public function consumeDailyCap(string $capability): bool
    {
        $capability = $capability === 'workspace' ? 'workspace' : 'member';
        $cap = $this->limit($capability . '_daily');
        if ($cap < 1) {
            return false;
        }
        $rows = $this->connection->query()->executeRaw(
            'INSERT INTO signup_daily_counters (capability, day, count, updated_at) '
            . 'VALUES (?, ?, 1, CURRENT_TIMESTAMP) '
            . 'ON CONFLICT (capability, day) DO UPDATE SET '
            . 'count = signup_daily_counters.count + 1, updated_at = CURRENT_TIMESTAMP '
            . 'WHERE signup_daily_counters.count < ? RETURNING count',
            [$capability, gmdate('Y-m-d'), $cap],
        );
        return $rows !== [];
    }

    public function hashIdentifier(string $value): string
    {
        $key = (string) config($this->context, 'app.key', '');
        if ($key === '') {
            $key = 'thallo-signup-counter';
        }
        return hash_hmac('sha256', $value, $key);
    }

    private function consumeWindow(string $dimension, string $identifier, int $seconds, int $cap): bool
    {
        if ($cap < 1) {
            return false;
        }
        $start = intdiv(time(), $seconds) * $seconds;
        $rows = $this->connection->query()->executeRaw(
            'INSERT INTO signup_rate_counters '
            . '(dimension, bucket_hash, window_start, count, updated_at) '
            . 'VALUES (?, ?, ?, 1, CURRENT_TIMESTAMP) '
            . 'ON CONFLICT (dimension, bucket_hash, window_start) DO UPDATE SET '
            . 'count = signup_rate_counters.count + 1, updated_at = CURRENT_TIMESTAMP '
            . 'WHERE signup_rate_counters.count < ? RETURNING count',
            [$dimension, $this->hashIdentifier($identifier), gmdate('Y-m-d H:i:s', $start), $cap],
        );
        return $rows !== [];
    }

    private function limit(string $key): int
    {
        return max(0, (int) config($this->context, 'signup.limits.' . $key, 0));
    }
}

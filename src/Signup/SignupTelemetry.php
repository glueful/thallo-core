<?php

declare(strict_types=1);

namespace Thallo\Core\Signup;

use Glueful\Logging\LogManager;
use Psr\Log\LoggerInterface;

final class SignupTelemetry
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    /** @param array<string,mixed> $context */
    public function record(string $event, array $context = []): void
    {
        try {
            $safe = [];
            foreach ($context as $key => $value) {
                if (in_array($key, ['otp', 'token', 'password', 'email', 'ip'], true)) {
                    continue;
                }
                $safe[$key] = $value;
            }
            $logger = $this->logger instanceof LogManager
                ? $this->logger->channel('security')
                : $this->logger;
            $logger->warning('Public signup security event', ['event' => $event] + $safe);
        } catch (\Throwable) {
            // Security telemetry is best-effort and must not alter the public response.
        }
    }
}

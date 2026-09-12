<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs\Responses;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only shape of the system health report
 * ({@see \Thallo\Core\Http\Controllers\HealthAdminController::show()}) — the framework health checks plus
 * runtime/system info.
 */
final class HealthData implements ResponseData
{
    /** @param list<HealthCheckData> $checks */
    public function __construct(
        public readonly string $status,
        public readonly string $version,
        public readonly string $environment,
        public readonly string $timestamp,
        public readonly string $php_version,
        public readonly int $memory_used,
        public readonly int $memory_peak,
        public readonly string $memory_limit,
        public readonly int $disk_free,
        public readonly int $disk_total,
        public readonly array $checks,
    ) {
    }
}

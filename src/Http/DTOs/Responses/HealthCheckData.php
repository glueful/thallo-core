<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs\Responses;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only shape of one health check ({@see \Thallo\Core\Http\Controllers\HealthAdminController}).
 * `status` is ok | warning | error. The detail lists are present only when the framework check
 * carries them (the config check: issues fail it, recommendations are advisory).
 */
final class HealthCheckData implements ResponseData
{
    /**
     * @param list<string>|null $issues
     * @param list<string>|null $warnings
     * @param list<string>|null $recommendations
     */
    public function __construct(
        public readonly string $name,
        public readonly string $status,
        public readonly string $message,
        public readonly ?array $issues = null,
        public readonly ?array $warnings = null,
        public readonly ?array $recommendations = null,
    ) {
    }
}

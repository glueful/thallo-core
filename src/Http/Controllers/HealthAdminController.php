<?php

declare(strict_types=1);

namespace Thallo\Core\Http\Controllers;

use Thallo\Core\Http\DTOs\Responses\HealthResultData;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Services\HealthService;
use Glueful\Support\Version;

/**
 * Authenticated system-health report for the admin Utilities › Health page.
 *
 * Wraps the framework's {@see HealthService::getOverallHealth()} (database / cache / extensions /
 * config checks) and adds runtime/system info (version, PHP, memory, disk). The framework's public
 * `/health` routes are unauthenticated and minimal, and `/health/detailed` is behind a different
 * permission — this exposes the report under the admin's `system.access` gate. Read-only.
 */
final class HealthAdminController
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    /** GET /v1/admin/health */
    #[ApiOperation(
        summary: 'System health',
        description: 'Overall health (database, cache, extensions, config) plus runtime info '
            . '(version, PHP, memory, disk). Read-only. Requires `system.access`.',
        tags: ['Utilities'],
    )]
    #[ApiResponse(200, schema: HealthResultData::class, description: 'Health report.')]
    public function show(): Response
    {
        $report = HealthService::getOverallHealth($this->context);

        $checks = [];
        foreach ((array) ($report['checks'] ?? []) as $name => $check) {
            $checks[] = self::shapeCheck((string) $name, $check);
        }

        $root = base_path($this->context, '');

        return Response::success([
            'health' => [
                'status' => (string) ($report['status'] ?? 'unknown'),
                'version' => Version::getFullVersion(),
                'environment' => (string) ($report['environment'] ?? ''),
                'timestamp' => (string) ($report['timestamp'] ?? date('c')),
                'php_version' => PHP_VERSION,
                'memory_used' => memory_get_usage(true),
                'memory_peak' => memory_get_peak_usage(true),
                'memory_limit' => (string) ini_get('memory_limit'),
                'disk_free' => (int) @disk_free_space($root),
                'disk_total' => (int) @disk_total_space($root),
                'checks' => $checks,
            ],
        ], 'Health retrieved.');
    }

    /**
     * One framework check as the admin shows it: name/status/message, plus the check's detail
     * lists (`issues`, `warnings`, `recommendations`) when present — the message alone
     * ("Configuration warnings detected") tells the operator nothing they can act on.
     *
     * @return array{name:string,status:string,message:string,issues?:list<string>,warnings?:list<string>,recommendations?:list<string>}
     */
    public static function shapeCheck(string $name, mixed $check): array
    {
        if (!is_array($check)) {
            return ['name' => $name, 'status' => 'unknown', 'message' => ''];
        }

        $shaped = [
            'name' => $name,
            'status' => (string) ($check['status'] ?? 'unknown'),
            'message' => (string) ($check['message'] ?? ''),
        ];
        foreach (['issues', 'warnings', 'recommendations'] as $list) {
            if (isset($check[$list]) && is_array($check[$list])) {
                $shaped[$list] = array_values(array_filter($check[$list], 'is_string'));
            }
        }

        return $shaped;
    }
}

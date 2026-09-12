<?php

declare(strict_types=1);

namespace Thallo\Core\Http\Controllers;

use Thallo\Core\Http\DTOs\ErrorResponse;
use Thallo\Core\Http\DTOs\Responses\RunTaskResultData;
use Thallo\Core\Http\DTOs\Responses\ScheduledTaskListData;
use Cron\CronExpression;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Http\Response;
use Glueful\Queue\QueueManager;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;

/**
 * Admin Utilities › Scheduled tasks — list the application's recurring jobs and run one on demand.
 *
 * The jobs are DEFINED IN CODE (config/schedule.php) — the framework scheduler is config/CLI-driven,
 * so this page reads that declaration (the source of truth) rather than the DB. `enabled` is the
 * configured state (read-only) and `next_run` is computed from the cron expression. "Run now"
 * ENQUEUES the job onto its queue (async) instead of running it synchronously, so heavy jobs (backup,
 * cleanup) don't block the request. Run history + enable/disable are intentionally out of scope (they
 * need a DB-backed scheduler). Gated by `system.access`.
 */
final class ScheduledTasksController
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    /** GET /v1/admin/scheduled-tasks */
    #[ApiOperation(
        summary: 'List scheduled tasks',
        description: 'The recurring jobs from config/schedule.php — name, cron schedule, computed '
            . 'next run, configured enabled state, handler, and queue. Requires `system.access`.',
        tags: ['Utilities'],
    )]
    #[ApiResponse(200, schema: ScheduledTaskListData::class, description: 'Scheduled tasks.')]
    public function index(): Response
    {
        $tasks = [];
        foreach ($this->jobs() as $job) {
            if (isset($job['name'], $job['schedule'])) {
                $tasks[] = $this->present($job);
            }
        }

        return Response::success(['tasks' => $tasks], 'Scheduled tasks retrieved.');
    }

    /** POST /v1/admin/scheduled-tasks/{name}/run */
    #[ApiOperation(
        summary: 'Run a scheduled task now',
        description: 'Queues the task\'s handler onto its queue to run asynchronously (it does not run '
            . 'inline). Requires `system.access`.',
        tags: ['Utilities'],
    )]
    #[ApiResponse(200, schema: RunTaskResultData::class, description: 'Task queued.')]
    #[ApiResponse(404, schema: ErrorResponse::class, envelope: false, description: 'No such task.')]
    #[ApiResponse(422, schema: ErrorResponse::class, envelope: false, description: 'Task has no runnable handler.')]
    public function run(string $name): Response
    {
        $job = $this->find($name);
        if ($job === null) {
            return Response::notFound('No such scheduled task.');
        }

        $handler = isset($job['handler_class']) ? (string) $job['handler_class'] : '';
        if ($handler === '' || !class_exists($handler)) {
            return Response::error('This task has no runnable handler.', 422);
        }

        $params = isset($job['parameters']) && is_array($job['parameters']) ? $job['parameters'] : [];
        $queue = isset($job['queue']) && is_string($job['queue']) ? $job['queue'] : null;

        // Enqueue (async) rather than run inline. QueueManager isn't container-registered; build it via
        // the framework's factory. The default queue is `database`, so the job runs when a worker picks
        // it up (it's queued immediately either way).
        try {
            QueueManager::setContext($this->context);
            $jobId = QueueManager::createDefault()->push($handler, $params, $queue);
        } catch (\Throwable $e) {
            return Response::error('Could not queue the task: ' . $e->getMessage(), 500);
        }

        return Response::success(['name' => $name, 'job_id' => (string) $jobId], "Queued “{$name}” to run.");
    }

    /** @return array<int,array<string,mixed>> */
    private function jobs(): array
    {
        $jobs = config($this->context, 'schedule.jobs', []);
        if (!is_array($jobs)) {
            return [];
        }

        return array_values(array_filter($jobs, 'is_array'));
    }

    /** @return array<string,mixed>|null */
    private function find(string $name): ?array
    {
        foreach ($this->jobs() as $job) {
            if ((string) ($job['name'] ?? '') === $name) {
                return $job;
            }
        }

        return null;
    }

    /**
     * @param array<string,mixed> $job
     * @return array<string,mixed>
     */
    private function present(array $job): array
    {
        $schedule = (string) ($job['schedule'] ?? '');

        return [
            'name' => (string) ($job['name'] ?? ''),
            'description' => (string) ($job['description'] ?? ''),
            'schedule' => $schedule,
            'next_run' => $this->nextRun($schedule),
            'enabled' => (bool) ($job['enabled'] ?? true),
            'handler_class' => (string) ($job['handler_class'] ?? ''),
            'queue' => (string) ($job['queue'] ?? ''),
        ];
    }

    private function nextRun(string $schedule): ?string
    {
        if ($schedule === '') {
            return null;
        }
        try {
            return (new CronExpression($schedule))->getNextRunDate()->format('c');
        } catch (\Throwable) {
            return null;
        }
    }
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Style\Classes;

use Glueful\Database\Connection;
use Glueful\Queue\QueueManager;
use Thallo\Core\Content\Jobs\RunStyleClassJob;

/**
 * Queue a detach-everywhere or remove-everywhere job (visual builder spec §4.5): lock the class
 * — a class write, so it serialises against every document write's reference guard — record the
 * job pinned to the class's current version, and push the queue job after commit.
 */
final class StyleClassJobService
{
    public function __construct(
        private readonly Connection $db,
        private readonly StyleClassRepository $classes,
        private readonly StyleClassJobRepository $jobs,
        private readonly QueueManager $queue,
    ) {
    }

    public function queue(string $classId, string $kind): string
    {
        if (!in_array($kind, StyleClassJobRepository::KINDS, true)) {
            throw new \InvalidArgumentException("unknown style class job kind {$kind}");
        }
        $active = $this->jobs->activeFor($classId);
        if ($active !== null) {
            throw new StyleClassLocked($classId, (string) $active['id']);
        }
        $class = $this->classes->find($classId) ?? throw new StyleClassNotFound($classId);
        $jobId = $this->jobs->start($classId, (int) $class['version'] + 1, $kind);
        // lock() bumps the version: the job pins the version the lock leaves behind.
        $this->classes->lock($classId, $jobId);
        $this->db->afterCommit(function () use ($jobId): void {
            $this->queue->push(RunStyleClassJob::class, ['job_id' => $jobId]);
        });
        return $jobId;
    }
}

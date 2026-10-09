<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Palette;

use Glueful\Database\Connection;
use Glueful\Helpers\Utils;
use Psr\Container\ContainerInterface;

/**
 * The `palette_jobs` records (custom palette spec §4.4). Every status change is a permitted
 * transition made under the palette lock: `completed` and `cancelled` are terminal, so a worker can
 * never revive a finished job or restore its reservations.
 */
final class PaletteJobRepository
{
    /** from => permitted targets; `running` → `running` is the resume of an interrupted job */
    private const TRANSITIONS = [
        'running' => ['completed', 'failed', 'cancelled', 'running'],
        'failed' => ['running', 'cancelled'],
    ];

    public function __construct(
        private readonly Connection $db,
        /** Resolves PaletteState lazily: it reads active() here, so a constructor edge would be a cycle. */
        private readonly ContainerInterface $container,
    ) {
    }

    /** Inside a held palette lock. */
    public function start(int $slot, string $to, ?string $contrastTo, ?string $actor, ?string $workspace): string
    {
        $id = Utils::generateNanoID(12);
        $now = gmdate('Y-m-d H:i:s');
        $this->db->table('palette_jobs')->insert([
            'id' => $id,
            'slot' => $slot,
            'to_token' => $to,
            'contrast_to_token' => $contrastTo,
            'status' => 'running',
            'passes' => 0,
            'work_items_total' => 0,
            'work_items_done' => 0,
            'work_items_failed' => 0,
            'failure_report' => json_encode([], JSON_THROW_ON_ERROR),
            'workspace' => $workspace,
            'created_by' => $actor,
            'created_at' => $now,
            'heartbeat_at' => $now,
        ]);
        return $id;
    }

    public function find(string $id): ?PaletteJob
    {
        $row = $this->db->table('palette_jobs')->where('id', '=', $id)->first();
        return $row === null ? null : self::hydrate($row);
    }

    /** @return list<PaletteJob> running or failed, oldest first */
    public function active(): array
    {
        $rows = $this->db->table('palette_jobs')->whereIn('status', ['running', 'failed'])
            ->orderBy('created_at', 'ASC')->get();
        return array_values(array_map(self::hydrate(...), $rows));
    }

    public function isActive(string $id): bool
    {
        $job = $this->find($id);
        return $job !== null && in_array($job->status, ['running', 'failed'], true);
    }

    /**
     * A permitted status change, under the palette lock; false (nothing written) for anything else.
     * `completed` records the palette generation it completed at.
     */
    public function transition(string $id, string $to, ?int $generation = null): bool
    {
        if (!$this->container->get(PaletteState::class)->heldInThisTransaction()) {
            throw new \LogicException('a palette job changes state only under the palette lock');
        }
        $job = $this->find($id);
        if ($job === null || !in_array($to, self::TRANSITIONS[$job->status] ?? [], true)) {
            return false;
        }
        $now = gmdate('Y-m-d H:i:s');
        $changes = ['status' => $to, 'heartbeat_at' => $now];
        if (in_array($to, ['completed', 'cancelled', 'failed'], true)) {
            $changes['finished_at'] = $now;
        }
        if ($to === 'running') {
            $changes['finished_at'] = null;
        }
        if ($to === 'completed' && $generation !== null) {
            $changes['completed_generation'] = $generation;
        }
        return $this->db->table('palette_jobs')->where('id', '=', $id)->where('status', '=', $job->status)
            ->update($changes) === 1;
    }

    public function beginPass(string $id, int $total): void
    {
        $job = $this->find($id);
        if ($job === null) {
            return;
        }
        $this->db->table('palette_jobs')->where('id', '=', $id)->where('status', '=', 'running')->update([
            'passes' => $job->passes + 1,
            'work_items_total' => $total,
            'work_items_done' => 0,
            'work_items_failed' => 0,
            'failure_report' => json_encode([], JSON_THROW_ON_ERROR),
            'heartbeat_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function incrementDone(string $id): void
    {
        $job = $this->find($id);
        if ($job !== null) {
            $this->db->table('palette_jobs')->where('id', '=', $id)->where('status', '=', 'running')
                ->update(['work_items_done' => $job->done + 1, 'heartbeat_at' => gmdate('Y-m-d H:i:s')]);
        }
    }

    public function touchHeartbeat(string $id): void
    {
        $this->db->table('palette_jobs')->where('id', '=', $id)->where('status', '=', 'running')
            ->update(['heartbeat_at' => gmdate('Y-m-d H:i:s')]);
    }

    public function recordFailure(string $id, string $source, string $docId, ?string $locale, string $reason): void
    {
        $job = $this->find($id);
        if ($job === null) {
            return;
        }
        $report = $job->failureReport;
        $report[] = ['source' => $source, 'id' => $docId, 'locale' => $locale, 'reason' => $reason];
        $this->db->table('palette_jobs')->where('id', '=', $id)->update([
            'work_items_failed' => $job->failed + 1,
            'failure_report' => json_encode($report, JSON_THROW_ON_ERROR),
        ]);
    }

    /** @param array<string,int> $counts per source */
    public function recordCounts(string $id, array $counts): void
    {
        $this->db->table('palette_jobs')->where('id', '=', $id)
            ->update(['counts' => json_encode($counts, JSON_THROW_ON_ERROR)]);
    }

    /** @param array<string,mixed> $row */
    private static function hydrate(array $row): PaletteJob
    {
        $report = is_string($row['failure_report'] ?? null) ? json_decode($row['failure_report'], true) : null;
        return new PaletteJob(
            (string) $row['id'],
            (int) $row['slot'],
            (string) $row['to_token'],
            isset($row['contrast_to_token']) ? (string) $row['contrast_to_token'] : null,
            (string) $row['status'],
            (int) $row['passes'],
            (int) $row['work_items_total'],
            (int) $row['work_items_done'],
            (int) $row['work_items_failed'],
            is_array($report) ? $report : [],
            isset($row['workspace']) ? (string) $row['workspace'] : null,
            isset($row['created_by']) ? (string) $row['created_by'] : null,
            isset($row['heartbeat_at']) ? (string) $row['heartbeat_at'] : null,
            isset($row['completed_generation']) ? (int) $row['completed_generation'] : null,
        );
    }
}

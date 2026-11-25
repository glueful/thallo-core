<?php

declare(strict_types=1);

namespace Thallo\Core\Content\DocsSync;

use Glueful\Database\Connection;

/** The docs sync's runs and their pages (docs sync design §4). */
final class DocsSyncRunRepository
{
    public const COUNTERS = ['created', 'updated', 'unchanged', 'skipped', 'failed', 'gone'];

    public function __construct(private readonly Connection $db)
    {
    }

    /** @param array<string,mixed> $row */
    public function create(array $row): string
    {
        $this->db->table('docs_sync_runs')->insert($row);
        return (string) $row['id'];
    }

    /** @return array<string,mixed>|null */
    public function find(string $id): ?array
    {
        $row = $this->db->table('docs_sync_runs')->where('id', '=', $id)->first();
        return is_array($row) ? $row : null;
    }

    /** @return list<array<string,mixed>> newest first */
    public function recent(int $limit): array
    {
        return array_values($this->db->table('docs_sync_runs')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get());
    }

    /** @return list<array<string,mixed>> */
    public function pages(string $run): array
    {
        return array_values($this->db->table('docs_sync_run_pages')
            ->where('run_id', '=', $run)
            ->orderBy('id', 'asc')
            ->get());
    }

    /** @return list<string> the paths this run already recorded */
    public function recordedPaths(string $run): array
    {
        return array_map(
            static fn (array $r): string => (string) $r['path'],
            $this->db->table('docs_sync_run_pages')->select(['path'])->where('run_id', '=', $run)->get(),
        );
    }

    /**
     * Record one page's result and count it, in the caller's transaction (the page's own).
     *
     * @param array{path: string, slug?: ?string, status: string, message?: ?string,
     *     broken_links?: list<string>, entry_uuid?: ?string} $page
     */
    public function recordPage(string $run, array $page): void
    {
        $status = $page['status'];
        if (!in_array($status, self::COUNTERS, true)) {
            throw new \InvalidArgumentException("Unknown page status \"{$status}\".");
        }
        $this->db->table('docs_sync_run_pages')->insert([
            'run_id' => $run,
            'path' => $page['path'],
            'slug' => $page['slug'] ?? null,
            'status' => $status,
            'message' => $page['message'] ?? null,
            'broken_links' => ($page['broken_links'] ?? []) === [] ? null : json_encode($page['broken_links']),
            'entry_uuid' => $page['entry_uuid'] ?? null,
        ]);
        $row = $this->find($run);
        $this->db->table('docs_sync_runs')->where('id', '=', $run)
            ->update([$status => (int) ($row[$status] ?? 0) + 1]);
    }

    /** @param array<string,mixed> $set */
    public function update(string $run, array $set): void
    {
        $this->db->table('docs_sync_runs')->where('id', '=', $run)->update($set);
    }

    /** A dispatch happened now; `dispatched` only from `reserved` (§6.0). */
    public function markDispatched(string $run): void
    {
        $this->db->table('docs_sync_runs')->where('id', '=', $run)
            ->update(['last_dispatch_at' => gmdate('Y-m-d H:i:s')]);
        $this->db->table('docs_sync_runs')->where('id', '=', $run)->where('status', '=', 'reserved')
            ->update(['status' => 'dispatched']);
    }

    /** Finish a run, once: a finished run is never finished again. The caller holds a fence. */
    public function finish(string $run, string $outcome, ?string $message = null): bool
    {
        return $this->db->table('docs_sync_runs')
            ->where('id', '=', $run)
            ->where('status', '!=', 'finished')
            ->update([
                'status' => 'finished',
                'outcome' => $outcome,
                'message' => $message,
                'finished_at' => gmdate('Y-m-d H:i:s'),
            ]) === 1;
    }

    /**
     * Finish a run whose attempt budget is spent (§6.4): cancelled if that was asked; `failed` with
     * the reason an attempt recorded (GitHub unreachable, a download cut short); else interrupted.
     * The caller holds a fence.
     *
     * @param array<string,mixed> $row
     */
    public function finishSpent(string $run, array $row): void
    {
        if ((bool) ($row['cancel_requested'] ?? false)) {
            $this->finish($run, 'cancelled');
            return;
        }
        $reason = is_string($row['message'] ?? null) && $row['message'] !== '' ? $row['message'] : null;
        $reason !== null
            ? $this->finish($run, 'failed', $reason)
            : $this->finish($run, 'interrupted', 'Stopped after ' . DocsSyncService::ATTEMPT_BUDGET
                . ' attempts without finishing.');
    }

    /** @return list<string> ids of runs finished before `$before` (UTC 'Y-m-d H:i:s') */
    public function finishedBefore(string $before): array
    {
        return array_map(
            static fn (array $r): string => (string) $r['id'],
            $this->db->table('docs_sync_runs')->select(['id'])
                ->where('status', '=', 'finished')
                ->where('finished_at', '<', $before)
                ->get(),
        );
    }

    /** @param list<string> $ids */
    public function delete(array $ids): void
    {
        if ($ids === []) {
            return;
        }
        $this->db->table('docs_sync_run_pages')->whereIn('run_id', $ids)->delete();
        $this->db->table('docs_sync_runs')->whereIn('id', $ids)->delete();
    }
}

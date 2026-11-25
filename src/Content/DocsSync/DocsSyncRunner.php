<?php

declare(strict_types=1);

namespace Thallo\Core\Content\DocsSync;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Queue\QueueManager;
use Glueful\Storage\StorageManager;
use Thallo\Core\Content\Jobs\DocsSyncJob;
use Thallo\Importers\Markdown\MarkdownFolderImport;
use Thallo\Importers\Markdown\MarkdownFolderRead;
use Thallo\Importers\Markdown\MarkdownZip;

/**
 * One execution attempt of a docs sync run (docs sync design §6.2–§6.3, §7). It claims the run's
 * lease, then resolves, downloads and reads with no transaction open, and writes each page in a
 * transaction of its own whose first write proves the lease. A page therefore never lands after the
 * slot was taken over or released, a page that fails leaves nothing (its savepoint is rolled back),
 * and each page's result and count commit with it, so an attempt killed at any point leaves exact
 * progress for the next one. Errors of the run itself (GitHub refused, an empty folder) finish it;
 * anything else escapes, the attempt dies, and recovery decides (§6.4).
 */
final class DocsSyncRunner
{
    /** @var (\Closure(string, ?string): void)|null */
    private ?\Closure $pause = null;

    public function __construct(
        private readonly Connection $db,
        private readonly DocsSyncStore $store,
        private readonly DocsSyncRunRepository $runs,
        private readonly GithubClient $github,
        private readonly MarkdownFolderImport $folders,
        private readonly StorageManager $storage,
        private readonly ?QueueManager $queue = null,
        private readonly ?ApplicationContext $context = null,
    ) {
    }

    /**
     * Tests only: called with ('download'), ('page', $path), ('before-finish') and ('finish') at the
     * points the concurrency proofs need. Production never sets it.
     *
     * @param \Closure(string, ?string): void $pause
     */
    public function pauseAt(\Closure $pause): void
    {
        $this->pause = $pause;
    }

    public function run(string $runId): void
    {
        $token = bin2hex(random_bytes(16));
        if (!$this->claim($runId, $token)) {
            return;
        }
        $row = (array) $this->runs->find($runId);
        $source = DocsSyncSource::fromArray((array) json_decode((string) $row['source'], true));
        $local = null;
        $dir = null;
        try {
            // 1. Resolve the followed version to a commit, once; later attempts reuse it.
            $sha = is_string($row['sha'] ?? null) ? $row['sha'] : null;
            if ($sha === null) {
                try {
                    [$ref, $sha] = $source->follow === 'branch'
                        ? [(string) $source->branch, $this->github->branchTip($source->repo, (string) $source->branch)]
                        : array_values($this->github->latestRelease($source->repo, $source->prereleases));
                } catch (GithubRefused $e) {
                    if ($e->transient) {
                        $this->recordReason($runId, $token, $e->getMessage());
                        throw $e; // unreachable: this attempt ends; recovery tries again
                    }
                    $this->finishWith($runId, $token, 'refused', $e->getMessage());
                    return;
                }
                $record = fn () => $this->runs->update($runId, ['ref' => $ref, 'sha' => $sha]);
                if (!$this->boundary($runId, $token, $record)) {
                    return;
                }
            }

            // 2. The archive at that commit: this attempt's own file, recorded only once complete.
            $archive = is_string($row['archive_path'] ?? null) ? $row['archive_path'] : null;
            if ($archive === null) {
                $archive = $this->download($runId, $token, $source, $sha);
                if ($archive === null) {
                    return;
                }
            }

            // 3. Read the folder: every page, resolved against the others.
            $local = $this->localCopy($archive);
            $dir = sys_get_temp_dir() . '/thallo-docs-sync-' . $runId . '-' . $token;
            mkdir($dir, 0700, true);
            try {
                MarkdownZip::extract($local, $dir, [], $source->exclude, within: $source->folder);
                $read = $this->folders->read($dir, [
                    'type' => $source->contentType,
                    'locale' => $source->locale,
                    'exclude' => $source->exclude,
                ]);
            } catch (\InvalidArgumentException | \Glueful\Http\Exceptions\Client\ForbiddenException $e) {
                // A wrong folder or type, or the importers capability switched off: the run is
                // refused as a whole, with the reason, before anything is written.
                $this->finishWith(
                    $runId,
                    $token,
                    'refused',
                    str_contains($e->getMessage(), 'holds no Markdown files')
                        ? "The folder `{$source->folder}` holds no pages."
                        : $e->getMessage(),
                );
                return;
            }

            $total = count($read->pages);
            if (!$this->boundary($runId, $token, fn () => $this->runs->update($runId, ['total' => $total]))) {
                return;
            }

            // 4. Each page not yet recorded for this run, one transaction each.
            $done = array_flip($this->runs->recordedPaths($runId));
            $options = [
                'publish' => $source->publish,
                'dry_run' => $row['mode'] === 'dry_run',
                'edit_base' => $source->editBase(),
                'actor' => is_string($row['created_by'] ?? null) ? $row['created_by'] : null,
            ];
            foreach ($read->pages as $page) {
                if (isset($done[$page->sourcePath])) {
                    continue;
                }
                if (!$this->writePage($runId, $token, $read, $page, $options)) {
                    return;
                }
            }

            // 5. Finish.
            $this->pause('before-finish');
            $this->finish($runId, $token, $read, (string) $row['mode'], (int) $row['revision']);
        } finally {
            if ($local !== null && is_file($local)) {
                @unlink($local);
            }
            if ($dir !== null) {
                MarkdownZip::remove($dir);
            }
        }
    }

    /**
     * §6.2: claim the lease for a new attempt and count it, in one transaction. A run that finished
     * or no longer holds the slot is left alone; one whose lease is live is re-queued for later, so
     * a run never depends on one message surviving.
     */
    private function claim(string $runId, string $token): bool
    {
        $claimed = $this->db->transaction(function () use ($runId, $token): ?bool {
            if (!$this->store->claim($runId, $token, DocsSyncService::LEASE_SECONDS)) {
                return null;
            }
            $row = (array) $this->runs->find($runId);
            if ((int) ($row['attempts'] ?? 0) >= DocsSyncService::ATTEMPT_BUDGET) {
                $this->runs->finishSpent($runId, $row);
                $this->store->release($runId);
                return false;
            }
            $this->store->advanceStatus($runId, 'running');
            $this->runs->update($runId, [
                'attempts' => (int) ($row['attempts'] ?? 0) + 1,
                'started_at' => $row['started_at'] ?? gmdate('Y-m-d H:i:s'),
            ]);
            return true;
        });
        if ($claimed !== null) {
            return $claimed;
        }
        $state = $this->store->state();
        $row = $this->runs->find($runId);
        if ($row !== null && $row['status'] !== 'finished' && $state['active_run'] === $runId) {
            // Another attempt's lease is live: come back after it, rather than give up.
            $this->queue?->later(60, DocsSyncJob::class, DocsSyncJob::payload(
                $runId,
                is_string($row['workspace'] ?? null) ? $row['workspace'] : null,
            ), $this->queueName());
        }
        return false;
    }

    /**
     * A short transaction proving the lease, checking for a Cancel, then running `$then`. False
     * (the attempt stops) when fenced out, or when the run was cancelled, which it then finishes.
     */
    private function boundary(string $runId, string $token, ?\Closure $then = null): bool
    {
        return $this->db->transaction(function () use ($runId, $token, $then): bool {
            if (!$this->store->ownerFence($runId, $token)) {
                return false;
            }
            if ($this->cancelRequested($runId)) {
                $this->runs->finish($runId, 'cancelled');
                $this->store->release($runId);
                return false;
            }
            if ($then !== null) {
                $then();
            }
            return true;
        });
    }

    /** §6.3 step 2. Returns the recorded archive path, or null when the attempt stopped. */
    private function download(string $runId, string $token, DocsSyncSource $source, string $sha): ?string
    {
        $part = sys_get_temp_dir() . "/thallo-docs-sync-{$runId}-{$token}.zip.part";
        $path = "docs-sync/{$runId}/{$token}.zip";
        $disk = $this->storage->disk('uploads');
        try {
            try {
                $this->github->download(
                    $source->repo,
                    $sha,
                    $part,
                    (int) ($this->context !== null
                        ? config($this->context, 'import_export.max_file_size', MarkdownZip::MAX_BYTES)
                        : MarkdownZip::MAX_BYTES),
                    fn (): bool => $this->boundary($runId, $token),
                );
            } catch (GithubRefused $e) {
                if ($e->transient) {
                    $this->recordReason($runId, $token, $e->getMessage());
                    throw $e; // cut short or unreachable: this attempt ends, the next downloads again
                }
                // A Cancel seen by the heartbeat already finished the run; otherwise the download failed.
                $this->finishWith($runId, $token, 'failed', $e->getMessage());
                return null;
            }
            $this->pause('download');
            $stream = fopen($part, 'rb');
            if ($stream === false) {
                throw new \RuntimeException('The downloaded archive could not be read back.');
            }
            try {
                $disk->writeStream($path, $stream);
            } finally {
                fclose($stream);
            }
        } finally {
            if (is_file($part)) {
                @unlink($part);
            }
        }
        if (!$this->boundary($runId, $token, fn () => $this->runs->update($runId, ['archive_path' => $path]))) {
            $disk->delete($path); // ours, never recorded: no one else will read it
            return null;
        }
        return $path;
    }

    /**
     * §6.3 step 4: one page in one transaction. False when the attempt must stop (fenced out, or
     * cancelled, which then finishes the run).
     *
     * @param array{publish: bool, dry_run: bool, edit_base: ?string, actor: ?string} $options
     */
    private function writePage(
        string $runId,
        string $token,
        MarkdownFolderRead $read,
        \Thallo\Importers\Markdown\MarkdownPage $page,
        array $options,
    ): bool {
        return $this->db->transaction(function () use ($runId, $token, $read, $page, $options): bool {
            if (!$this->store->ownerFence($runId, $token)) {
                return false;
            }
            if ($this->cancelRequested($runId)) {
                $this->runs->finish($runId, 'cancelled');
                $this->store->release($runId);
                return false;
            }
            $this->pause('page', $page->sourcePath);
            try {
                $result = $this->db->transaction(
                    fn (): array => $this->folders->writePage($read, $page, $options),
                );
            } catch (\Throwable $e) {
                $result = [
                    'path' => $page->sourcePath,
                    'slug' => $page->slug,
                    'status' => 'failed',
                    'message' => $e instanceof \Thallo\Contracts\Authoring\ValidationFailed
                        ? 'Not valid: ' . json_encode($e->errors())
                        : $e->getMessage(),
                ];
            }
            $this->runs->recordPage($runId, [
                'path' => $page->sourcePath,
                'slug' => $page->slug,
                'status' => (string) $result['status'],
                'message' => isset($result['message']) ? (string) $result['message'] : null,
                'broken_links' => $page->brokenLinks,
            ]);
            return true;
        });
    }

    /** §6.3 step 5, under the owner's fence; a Cancel committed first wins (§6.5). */
    private function finish(string $runId, string $token, MarkdownFolderRead $read, string $mode, int $revision): void
    {
        $won = $this->db->transaction(function () use ($runId, $token, $read, $mode, $revision): bool {
            if (!$this->store->ownerFence($runId, $token)) {
                return false; // fenced out: the run, and its archive, are a successor's
            }
            $this->pause('finish');
            if ($this->cancelRequested($runId)) {
                $this->runs->finish($runId, 'cancelled');
                $this->store->release($runId);
                return true;
            }
            $recorded = array_flip($this->runs->recordedPaths($runId));
            foreach ($read->missing as $gone) {
                if (!isset($recorded[$gone['source_path']])) {
                    $this->runs->recordPage($runId, ['path' => $gone['source_path'], 'status' => 'gone']);
                }
            }
            $row = (array) $this->runs->find($runId);
            $written = (int) $row['created'] + (int) $row['updated'] + (int) $row['unchanged'];
            $failed = (int) $row['failed'];
            $outcome = $failed === 0 ? 'success' : ($written > 0 ? 'partial' : 'failed');
            $this->runs->finish(
                $runId,
                $outcome,
                $outcome === 'failed' ? 'Every page failed; see the pages for why.' : null,
            );
            if ($mode === 'commit' && $outcome !== 'failed') {
                $state = $this->store->state();
                $current = $state['synced_run'] !== null ? $this->runs->find($state['synced_run']) : null;
                $newer = $current === null || (string) $current['created_at'] <= (string) $row['created_at'];
                if ($state['revision'] === $revision && $newer) {
                    $this->store->markSynced($runId);
                }
            }
            $this->store->release($runId);
            return true;
        });
        if (!$won) {
            return;
        }
        $archive = (string) ($this->runs->find($runId)['archive_path'] ?? '');
        if ($archive !== '') {
            $this->storage->disk('uploads')->delete($archive);
        }
    }

    /** Finish the run as `$outcome` under the owner's fence (refused, failed). */
    private function finishWith(string $runId, string $token, string $outcome, ?string $message): void
    {
        $this->db->transaction(function () use ($runId, $token, $outcome, $message): void {
            if (!$this->store->ownerFence($runId, $token)) {
                return;
            }
            if ($this->cancelRequested($runId)) {
                $this->runs->finish($runId, 'cancelled');
            } else {
                $this->runs->finish($runId, $outcome, $message);
            }
            $this->store->release($runId);
        });
    }

    /**
     * Keep a transient error's reason on the run, under the owner's fence, so a run whose attempts
     * are spent says why (§7: a download error is `failed`, with its message).
     */
    private function recordReason(string $runId, string $token, string $message): void
    {
        $this->db->transaction(function () use ($runId, $token, $message): void {
            if ($this->store->ownerFence($runId, $token)) {
                $this->runs->update($runId, ['message' => $message]);
            }
        });
    }

    private function cancelRequested(string $runId): bool
    {
        return (bool) ($this->runs->find($runId)['cancel_requested'] ?? false);
    }

    private function localCopy(string $archive): string
    {
        $local = sys_get_temp_dir() . '/thallo-docs-sync-read-' . bin2hex(random_bytes(8)) . '.zip';
        $in = $this->storage->disk('uploads')->readStream($archive);
        $out = fopen($local, 'wb');
        if ($out === false) {
            throw new \RuntimeException('The archive could not be copied for reading.');
        }
        stream_copy_to_stream($in, $out);
        fclose($out);
        if (is_resource($in)) {
            fclose($in);
        }
        return $local;
    }

    private function pause(string $at, ?string $path = null): void
    {
        if ($this->pause !== null) {
            ($this->pause)($at, $path);
        }
    }

    private function queueName(): string
    {
        $queue = $this->context !== null ? config($this->context, 'import_export.queue', 'import-export') : null;
        return is_string($queue) && $queue !== '' ? $queue : 'import-export';
    }
}

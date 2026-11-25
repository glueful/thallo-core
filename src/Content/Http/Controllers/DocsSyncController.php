<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\Controllers;

use Glueful\Cache\CacheStore;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Core\Content\DocsSync\DocsSyncConflict;
use Thallo\Core\Content\DocsSync\DocsSyncRunRepository;
use Thallo\Core\Content\DocsSync\DocsSyncService;
use Thallo\Core\Content\DocsSync\DocsSyncSource;
use Thallo\Core\Content\DocsSync\DocsSyncStore;
use Thallo\Core\Content\DocsSync\GithubClient;
use Thallo\Core\Content\DocsSync\GithubRefused;

/**
 * Documentation from GitHub (docs sync design §6, §9): the source, Sync now and Preview, their
 * history, and the note saying whether the repo has moved since the last sync. Reading the card
 * also runs recovery, so a stuck run is picked up when someone looks.
 */
final class DocsSyncController
{
    private const NOTE_SECONDS = 300;

    public function __construct(
        private readonly DocsSyncService $sync,
        private readonly DocsSyncStore $store,
        private readonly DocsSyncRunRepository $runs,
        private readonly GithubClient $github,
        private readonly ?CacheStore $cache = null,
    ) {
    }

    #[ApiOperation(
        summary: 'Documentation from GitHub',
        tags: ['Thallo Admin'],
        description: 'The docs source, the run holding the slot, the last sync that landed, the ten most '
            . 'recent runs, and whether the repo has moved since. Requires `import_export.view`.',
    )]
    #[ApiResponse(200, description: 'The docs sync state.')]
    public function show(): Response
    {
        $this->sync->recover();
        $state = $this->store->state();
        $synced = $state['synced_run'] !== null ? $this->runs->find($state['synced_run']) : null;

        return Response::success([
            'source' => $state['source']?->toArray(),
            'revision' => $state['revision'],
            'active_run' => $state['active_run'],
            'releasable' => $state['active_run'] !== null
                && ($state['lease_token'] === null
                    || DocsSyncService::secondsSince($state['lease_at']) >= DocsSyncService::LEASE_SECONDS),
            'synced' => $synced,
            'recent' => $this->runs->recent(10),
            'note' => $this->note($state['source'], $state['revision'], $synced),
        ]);
    }

    #[ApiOperation(summary: 'A docs sync run and its pages', tags: ['Thallo Admin'])]
    #[ApiResponse(200, description: 'The run, with each page and what happened to it.')]
    #[ApiResponse(404, description: 'No such run.')]
    public function runPages(string $run): Response
    {
        $row = $this->runs->find($run);
        if ($row === null) {
            return Response::notFound('No such docs sync run.');
        }
        return Response::success(['run' => $row, 'pages' => $this->runs->pages($run)]);
    }

    #[ApiOperation(
        summary: 'Save the docs source',
        tags: ['Thallo Admin'],
        description: 'Checks the source against GitHub (a public repo; a version tag or the branch) and '
            . 'saves it as a new revision. Refused while a sync runs. Requires `content.manage` and '
            . '`import_export.run_import`.',
    )]
    #[ApiResponse(200, description: 'Saved.')]
    #[ApiResponse(409, description: 'A sync is running.')]
    #[ApiResponse(422, description: 'The source, or GitHub, refused it; the message names the field.')]
    public function saveSource(Request $request): Response
    {
        $input = json_decode((string) $request->getContent(), true);
        $input = is_array($input) ? $input : [];
        try {
            $source = DocsSyncSource::fromArray($input + ['edit_branch' => '']);
            $repo = $this->github->repo($source->repo);
            if ($source->follow === 'release') {
                $this->github->latestRelease($source->repo, $source->prereleases);
            } else {
                $this->github->branchTip($source->repo, (string) $source->branch);
            }
            $source = DocsSyncSource::fromArray([
                'edit_branch' => $source->follow === 'branch' ? (string) $source->branch : $repo['default_branch'],
            ] + $source->toArray());
        } catch (\InvalidArgumentException $e) {
            return Response::validation([self::fieldOf($e->getMessage()) => $e->getMessage()]);
        } catch (GithubRefused $e) {
            return Response::validation(['repo' => $e->getMessage()]);
        }
        try {
            $revision = $this->store->saveSource($source);
        } catch (DocsSyncConflict $e) {
            return Response::error($e->getMessage(), 409);
        }
        return Response::success(['source' => $source->toArray(), 'revision' => $revision], 'Docs source saved.');
    }

    #[ApiOperation(summary: 'Remove the docs source', tags: ['Thallo Admin'])]
    #[ApiResponse(200, description: 'Removed; pages already imported stay.')]
    #[ApiResponse(409, description: 'A sync is running.')]
    public function deleteSource(): Response
    {
        try {
            $this->store->removeSource();
        } catch (DocsSyncConflict $e) {
            return Response::error($e->getMessage(), 409);
        }
        return Response::success([], 'Docs source removed. Pages already imported stay.');
    }

    #[ApiOperation(
        summary: 'Sync now, or Preview',
        tags: ['Thallo Admin'],
        description: '`mode`: `commit` (Sync now) or `dry_run` (Preview). Takes the site\'s single slot '
            . 'and queues the sync on the import-export queue.',
    )]
    #[ApiResponse(202, description: 'The run, queued.')]
    #[ApiResponse(409, description: 'A sync is running, there is no source, or the source just changed.')]
    #[ApiResponse(422, description: 'Unknown mode.')]
    public function start(Request $request): Response
    {
        $input = json_decode((string) $request->getContent(), true);
        $mode = is_array($input) && is_string($input['mode'] ?? null) ? $input['mode'] : '';
        $actor = $request->attributes->get('user');
        $actor = is_array($actor) && is_string($actor['uuid'] ?? null) ? $actor['uuid'] : null;
        try {
            $run = $this->sync->start($mode, $actor);
        } catch (\InvalidArgumentException $e) {
            return Response::validation(['mode' => $e->getMessage()]);
        } catch (DocsSyncConflict $e) {
            return Response::error($e->getMessage(), 409, ['reason' => $e->reason]);
        }
        $response = Response::success(['run' => $run], $mode === 'dry_run' ? 'Preview queued.' : 'Sync queued.');
        $response->setStatusCode(202);
        return $response;
    }

    #[ApiOperation(summary: 'Cancel a docs sync run', tags: ['Thallo Admin'])]
    #[ApiResponse(200, description: '`finished`: whether it is over now; `outcome` when it is.')]
    public function cancel(string $run): Response
    {
        return Response::success($this->sync->cancel($run));
    }

    #[ApiOperation(summary: 'Release a docs sync run that stopped reporting in', tags: ['Thallo Admin'])]
    #[ApiResponse(200, description: '`released`: false while its attempt is still working.')]
    public function release(string $run): Response
    {
        return Response::success($this->sync->release($run));
    }

    /**
     * Whether the repo has moved since the last sync, compared by commit (§5). Cached five minutes
     * per source revision and last sync, to keep within GitHub's anonymous limit.
     *
     * @param array<string,mixed>|null $synced
     * @return array{state: string, followed: ?array{ref: string, sha: string}, message: ?string}
     */
    private function note(?DocsSyncSource $source, int $revision, ?array $synced): array
    {
        if ($source === null) {
            return ['state' => 'none', 'followed' => null, 'message' => null];
        }
        // Keyed by everything the note depends on (the whole source, the last sync's commit), so a
        // note made for one source, in this workspace or another at the same revision, is never
        // shown for another.
        $key = 'docs_sync.note.' . sha1((string) json_encode([
            $source->toArray(), $revision, $synced['id'] ?? null, $synced['sha'] ?? null,
        ]));
        $cached = $this->cache?->get($key);
        if (is_array($cached)) {
            return $cached;
        }
        try {
            if ($source->follow === 'branch') {
                $branch = (string) $source->branch;
                $followed = ['ref' => $branch, 'sha' => $this->github->branchTip($source->repo, $branch)];
            } else {
                $latest = $this->github->latestRelease($source->repo, $source->prereleases);
                $followed = ['ref' => $latest['tag'], 'sha' => $latest['sha']];
            }
        } catch (GithubRefused $e) {
            $state = str_contains($e->getMessage(), 'hourly limit') ? 'rate_limited' : 'unreachable';
            return ['state' => $state, 'followed' => null, 'message' => $e->getMessage()];
        }
        $state = match (true) {
            $synced === null => 'not_synced',
            (string) $synced['sha'] === $followed['sha'] => 'up_to_date',
            $source->follow === 'branch' => 'branch_moved',
            (string) $synced['ref'] === $followed['ref'] => 'tag_moved',
            default => 'newer',
        };
        $note = ['state' => $state, 'followed' => $followed, 'message' => null];
        $this->cache?->set($key, $note, self::NOTE_SECONDS);
        return $note;
    }

    private static function fieldOf(string $message): string
    {
        return preg_match('/\A([a-z_]+):/', $message, $m) === 1 ? $m[1] : 'source';
    }
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

/**
 * The one update operation (spec §5). Every transition is a compare-and-set under a short exclusive
 * lock on state.lock, on the operation id, status and owner the caller expects. A runner that lost
 * ownership (it was paused, found dead, or the operation finished) gets Fenced, never a write.
 */
final class OperationStore
{
    public const FORMAT = 1;
    public const RECOVERY_OPTIONS = ['rollback', 'retry_provision', 'reopen'];

    public function __construct(private readonly Paths $paths, private readonly \Closure $now)
    {
    }

    public function current(): ?array
    {
        return AtomicFile::readJson($this->paths->operation());
    }

    /** @return array{0: array, 1: string} */
    public function start(string $target, string $from, string $requestedBy): array
    {
        return $this->locked(function () use ($target, $from, $requestedBy): array {
            $op = $this->current();
            if ($op !== null && $op['status'] === Status::RECOVERY_REQUIRED) {
                throw new UpdateRefused('recovery_required');
            }
            if ($op !== null && !Status::isTerminal($op['status'])) {
                throw new UpdateRefused('in_progress');
            }
            $token = bin2hex(random_bytes(32));
            $op = [
                'format' => self::FORMAT,
                'id' => gmdate('YmdHis', ($this->now)()) . '-' . bin2hex(random_bytes(3)),
                'target' => $target,
                'from' => $from,
                'status' => Status::REQUESTED,
                'step' => null,
                'steps' => [],
                'requested_by' => $requestedBy,
                'requested_at' => ($this->now)(),
                'token_hash' => hash('sha256', $token),
                'owner' => null,
                'pid' => null,
                'pgid' => null,
                'heartbeat_at' => null,
                'provision_started' => false,
                'reopen_ready_at' => null,
                'journal' => [],
                'backup' => null,
                'provision_manifest' => null,
                'recovery_request' => null,
                'recovering' => null,
                'error' => null,
            ];
            $this->write($op);
            return [$op, $token];
        });
    }

    public function claim(string $id, int $pid, ?int $pgid): ?array
    {
        return $this->transition($id, static fn (array $op): bool => $op['status'] === Status::REQUESTED, $pid, $pgid);
    }

    public function claimRecovery(string $id, int $pid, ?int $pgid): ?array
    {
        return $this->transition(
            $id,
            static fn (array $op): bool => $op['status'] === Status::RECOVERY_REQUIRED
                && $op['recovery_request'] !== null,
            $pid,
            $pgid,
            true,
        );
    }

    public function takeOver(string $id, string $deadOwner, int $pid, ?int $pgid): ?array
    {
        return $this->transition(
            $id,
            static fn (array $op): bool => $op['status'] === Status::RUNNING
                && hash_equals((string) $op['owner'], $deadOwner),
            $pid,
            $pgid,
        );
    }

    public function cancel(string $id): bool
    {
        return $this->locked(function () use ($id): bool {
            $op = $this->current();
            if ($op === null || $op['id'] !== $id || $op['status'] !== Status::REQUESTED) {
                return false;
            }
            $op['status'] = Status::CANCELLED;
            $this->write($op);
            return true;
        });
    }

    public function update(string $id, string $owner, callable $mutate): array
    {
        return $this->locked(function () use ($id, $owner, $mutate): array {
            $op = $this->owned($id, $owner);
            $op = $mutate($op);
            $op['id'] = $id; // a mutation can never move the write to another operation
            $op['owner'] = $owner;
            $this->write($op);
            return $op;
        });
    }

    public function finish(string $id, string $owner, string $status, ?string $error = null): array
    {
        return $this->update($id, $owner, static function (array $op) use ($status, $error): array {
            $op['status'] = $status;
            $op['error'] = $error;
            $op['recovery_request'] = null;
            $op['recovering'] = null;
            return $op;
        });
    }

    public function verifyToken(string $token): bool
    {
        $op = $this->current();
        return $op !== null && $token !== '' && hash_equals((string) $op['token_hash'], hash('sha256', $token));
    }

    public function rotateToken(string $id): ?string
    {
        return $this->locked(function () use ($id): ?string {
            $op = $this->current();
            if ($op === null || $op['id'] !== $id) {
                return null;
            }
            $token = bin2hex(random_bytes(32));
            $op['token_hash'] = hash('sha256', $token);
            $this->write($op);
            return $token;
        });
    }

    public function requestRecovery(string $token, string $option): bool
    {
        return $this->locked(function () use ($token, $option): bool {
            $op = $this->current();
            if ($op === null || $token === '' || !hash_equals((string) $op['token_hash'], hash('sha256', $token))) {
                return false;
            }
            return $this->recordRecovery($op, $option);
        });
    }

    public function requestRecoveryAsOwner(string $id, string $option): bool
    {
        return $this->locked(function () use ($id, $option): bool {
            $op = $this->current();
            return $op !== null && $op['id'] === $id && $this->recordRecovery($op, $option);
        });
    }

    private function recordRecovery(array $op, string $option): bool
    {
        if (!in_array($option, self::RECOVERY_OPTIONS, true) || $op['status'] !== Status::RECOVERY_REQUIRED) {
            return false;
        }
        $op['recovery_request'] = $option;
        $this->write($op);
        return true;
    }

    private function transition(string $id, \Closure $allowed, int $pid, ?int $pgid, bool $recovery = false): ?array
    {
        return $this->locked(function () use ($id, $allowed, $pid, $pgid, $recovery): ?array {
            $op = $this->current();
            if ($op === null || $op['id'] !== $id || !$allowed($op)) {
                return null;
            }
            if ($recovery) {
                $op['recovering'] = $op['recovery_request'];
                $op['recovery_request'] = null;
            }
            $op['status'] = Status::RUNNING;
            $op['owner'] = bin2hex(random_bytes(16));
            $op['pid'] = $pid;
            $op['pgid'] = $pgid;
            $op['heartbeat_at'] = ($this->now)();
            $this->write($op);
            return $op;
        });
    }

    private function owned(string $id, string $owner): array
    {
        $op = $this->current();
        if (
            $op === null
            || $op['id'] !== $id
            || $op['status'] !== Status::RUNNING
            || !hash_equals((string) $op['owner'], $owner)
        ) {
            throw new Fenced("Operation {$id} is no longer owned by this runner");
        }
        return $op;
    }

    private function write(array $op): void
    {
        AtomicFile::writeJson($this->paths->operation(), $op);
    }

    private function locked(callable $fn): mixed
    {
        if (!is_dir($this->paths->dir())) {
            mkdir($this->paths->dir(), 0770, true);
        }
        return FileLock::withExclusive($this->paths->stateLock(), $fn);
    }
}

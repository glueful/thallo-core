<?php

declare(strict_types=1);

namespace Thallo\Core\Signup;

use Glueful\Database\Connection;
use Glueful\Helpers\Utils;

final class SignupIntentRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /** @param array<string,mixed> $fields */
    public function create(array $fields): string
    {
        $uuid = Utils::generateNanoID(12);
        $now = gmdate('Y-m-d H:i:s');
        $this->connection->table('signup_intents')->insert(array_merge([
            'uuid' => $uuid,
            'status' => 'pending',
            'completion_outcome' => null,
            'consumed_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ], $fields));
        return $uuid;
    }

    /** @return array<string,mixed>|null */
    public function find(string $uuid): ?array
    {
        $row = $this->connection->table('signup_intents')->where('uuid', '=', $uuid)->first();
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function lockForUpdate(string $uuid): ?array
    {
        if ($this->connection->transactionLevel() < 1) {
            throw new \LogicException('Signup intent locking requires an active transaction.');
        }
        $row = $this->connection->query()->executeRawFirst(
            'SELECT *, expires_at <= CURRENT_TIMESTAMP AS is_expired '
            . 'FROM signup_intents WHERE uuid = ? FOR UPDATE',
            [$uuid],
        );
        if ($row === null) {
            return null;
        }
        if ($this->truthy($row['is_expired'] ?? false) && ($row['status'] ?? null) !== 'consumed') {
            $this->hardDelete($uuid);
            return null;
        }
        unset($row['is_expired']);
        return $row;
    }

    public function transition(string $uuid, string $from, string $to): bool
    {
        return $this->connection->table('signup_intents')
            ->where('uuid', '=', $uuid)
            ->where('status', '=', $from)
            ->update(['status' => $to, 'updated_at' => gmdate('Y-m-d H:i:s')]) === 1;
    }

    public function updateUsername(string $uuid, string $username): void
    {
        $this->connection->table('signup_intents')->where('uuid', '=', $uuid)->update([
            'username' => $username,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function updateWorkspaceTarget(string $uuid, string $slug, string $name): void
    {
        $this->connection->table('signup_intents')->where('uuid', '=', $uuid)->update([
            'desired_slug' => $slug,
            'workspace_name' => $name,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function setResults(string $uuid, string $userUuid, string $tenantUuid): void
    {
        $this->connection->table('signup_intents')->where('uuid', '=', $uuid)->update([
            'result_user_uuid' => $userUuid,
            'result_tenant_uuid' => $tenantUuid,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function consume(string $uuid, string $outcome): void
    {
        $operation = function () use ($uuid, $outcome): void {
            $now = gmdate('Y-m-d H:i:s');
            $this->connection->table('signup_intents')->where('uuid', '=', $uuid)->update([
                'status' => 'consumed',
                'completion_outcome' => $outcome,
                'password_hash' => null,
                'request_ip_hash' => null,
                'consumed_at' => $now,
                'updated_at' => $now,
            ]);
            $this->connection->table('signup_verifiers')->where('intent_uuid', '=', $uuid)->delete();
            $this->connection->table('signup_continuations')->where('intent_uuid', '=', $uuid)->delete();
        };
        if ($this->connection->transactionLevel() > 0) {
            $operation();
            return;
        }
        $this->connection->transaction($operation);
    }

    public function hardDelete(string $uuid): void
    {
        $this->connection->table('signup_verifiers')->where('intent_uuid', '=', $uuid)->delete();
        $this->connection->table('signup_continuations')->where('intent_uuid', '=', $uuid)->delete();
        $this->connection->table('signup_intents')->where('uuid', '=', $uuid)->delete();
    }

    public function sweepExpired(): int
    {
        return $this->sweep(
            "SELECT uuid FROM signup_intents WHERE status <> 'consumed' AND expires_at <= CURRENT_TIMESTAMP",
            [],
        );
    }

    public function sweepConsumedBefore(\DateTimeImmutable $cutoff): int
    {
        return $this->sweep(
            "SELECT uuid FROM signup_intents WHERE status = 'consumed' AND consumed_at < ?",
            [$cutoff->format('Y-m-d H:i:s')],
        );
    }

    public function pruneRateCountersBefore(\DateTimeImmutable $cutoff): int
    {
        return $this->connection->query()->executeModification(
            'DELETE FROM signup_rate_counters WHERE window_start < ?',
            [$cutoff->format('Y-m-d H:i:s')],
        );
    }

    /** @param array<int,mixed> $bindings */
    private function sweep(string $sql, array $bindings): int
    {
        return $this->connection->transaction(function () use ($sql, $bindings): int {
            $rows = $this->connection->query()->executeRaw($sql . ' FOR UPDATE', $bindings);
            foreach ($rows as $row) {
                $this->hardDelete((string) $row['uuid']);
            }
            return count($rows);
        });
    }

    private function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Signup;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;

final class ContinuationTokens
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly Connection $connection,
    ) {
    }

    public function issue(string $intentUuid): string
    {
        $token = $this->newToken();
        $row = [
            'current_hash' => $this->hash($token),
            'previous_hash' => null,
            'previous_operation_id' => null,
            'previous_valid_until' => null,
            'last_operation_id' => null,
            'last_operation_payload_hash' => null,
            'last_operation_status' => null,
            'last_operation_result' => null,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];
        $existing = $this->connection->table('signup_continuations')
            ->where('intent_uuid', '=', $intentUuid)->first();
        if ($existing === null) {
            $this->connection->table('signup_continuations')->insert($row + ['intent_uuid' => $intentUuid]);
        } else {
            $this->connection->table('signup_continuations')
                ->where('intent_uuid', '=', $intentUuid)->update($row);
        }
        return $token;
    }

    public function authorizeInTransaction(
        string $intentUuid,
        string $token,
        string $operationId,
        string $canonicalPayloadHash,
    ): ContinuationGrant {
        if ($this->connection->transactionLevel() < 1) {
            throw new \LogicException('Continuation authorization requires an active transaction.');
        }
        if ($operationId === '' || preg_match('/^[A-Za-z0-9._:-]{1,96}$/', $operationId) !== 1) {
            throw new SignupException('A valid idempotency key is required.', 422);
        }
        $row = $this->connection->query()->executeRawFirst(
            'SELECT *, previous_valid_until >= CURRENT_TIMESTAMP AS previous_valid '
            . 'FROM signup_continuations WHERE intent_uuid = ? FOR UPDATE',
            [$intentUuid],
        );
        if ($row === null) {
            throw new SignupException('Continuation credential is unavailable.', 401);
        }
        $hash = $this->hash($token);
        $current = is_string($row['current_hash'] ?? null) ? $row['current_hash'] : '';
        $previous = is_string($row['previous_hash'] ?? null) ? $row['previous_hash'] : '';
        $sameOperation = ($row['last_operation_id'] ?? null) === $operationId
            && ($row['last_operation_payload_hash'] ?? null) === $canonicalPayloadHash;
        $result = $this->result($row['last_operation_result'] ?? null);

        if ($current !== '' && hash_equals($current, $hash)) {
            if ($sameOperation && ($row['last_operation_status'] ?? null) === 'complete') {
                return new ContinuationGrant($token, true, false, $result);
            }
            return $this->rotate(
                $intentUuid,
                $current,
                $operationId,
                $canonicalPayloadHash,
                $sameOperation && ($row['last_operation_status'] ?? null) === 'in_progress',
            );
        }

        if (
            $previous !== ''
            && hash_equals($previous, $hash)
            && $this->truthy($row['previous_valid'] ?? false)
            && ($row['previous_operation_id'] ?? null) === $operationId
            && $sameOperation
        ) {
            $fresh = $this->newToken();
            $this->connection->table('signup_continuations')
                ->where('intent_uuid', '=', $intentUuid)
                ->update([
                    'current_hash' => $this->hash($fresh),
                    'previous_hash' => null,
                    'previous_operation_id' => null,
                    'previous_valid_until' => null,
                    'updated_at' => gmdate('Y-m-d H:i:s'),
                ]);
            $complete = ($row['last_operation_status'] ?? null) === 'complete';
            return new ContinuationGrant($fresh, $complete, !$complete, $complete ? $result : null);
        }

        throw new SignupException('Continuation credential was rejected.', 401, [], 'CONTINUATION_REJECTED');
    }

    /** @param array<string,mixed> $result */
    public function completeInTransaction(string $intentUuid, string $operationId, array $result): void
    {
        if ($this->connection->transactionLevel() < 1) {
            throw new \LogicException('Continuation completion requires an active transaction.');
        }
        $updated = $this->connection->table('signup_continuations')
            ->where('intent_uuid', '=', $intentUuid)
            ->where('last_operation_id', '=', $operationId)
            ->where('last_operation_status', '=', 'in_progress')
            ->update([
                'last_operation_status' => 'complete',
                'last_operation_result' => json_encode($result, JSON_THROW_ON_ERROR),
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
        if ($updated !== 1) {
            throw new SignupException('Continuation operation is stale.', 409);
        }
    }

    public function invalidate(string $intentUuid): void
    {
        $this->connection->table('signup_continuations')->where('intent_uuid', '=', $intentUuid)->update([
            'current_hash' => null,
            'previous_hash' => null,
            'previous_operation_id' => null,
            'previous_valid_until' => null,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function resetForReverification(string $intentUuid): void
    {
        $this->connection->table('signup_continuations')->where('intent_uuid', '=', $intentUuid)->delete();
    }

    private function rotate(
        string $intentUuid,
        string $oldCurrent,
        string $operationId,
        string $payloadHash,
        bool $resume,
    ): ContinuationGrant {
        $fresh = $this->newToken();
        $grace = max(30, (int) config($this->context, 'signup.continuation.grace_seconds', 300));
        $this->connection->table('signup_continuations')->where('intent_uuid', '=', $intentUuid)->update([
            'current_hash' => $this->hash($fresh),
            'previous_hash' => $oldCurrent,
            'previous_operation_id' => $operationId,
            'previous_valid_until' => gmdate('Y-m-d H:i:s', time() + $grace),
            'last_operation_id' => $operationId,
            'last_operation_payload_hash' => $payloadHash,
            'last_operation_status' => 'in_progress',
            'last_operation_result' => null,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        return new ContinuationGrant($fresh, false, $resume);
    }

    private function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @return array<string,mixed>|null */
    private function result(mixed $value): ?array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return null;
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function truthy(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 't';
    }
}

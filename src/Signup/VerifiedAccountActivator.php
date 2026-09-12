<?php

declare(strict_types=1);

namespace Thallo\Core\Signup;

use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Glueful\Extensions\Users\Repositories\UserRepository;

/**
 * Everything an activation does regardless of WHY the account is being created, up to and
 * including identity creation — then a purpose-specific continuation, then commit.
 *
 * Extracting this is what makes "a shopper gets identity, not authority" structural: customer
 * activation passes a continuation that does nothing, so there is no code path from it to
 * `addMember()`. The alternative — one service that remembers to skip a line — is a boundary
 * that holds only until somebody edits it.
 *
 * Every decision — kind, status, consumed replay, existing-email, username conflict — is made
 * UNDER the row lock. The initial read establishes only the tenant to run inside; deciding kind
 * before the lock (as the pre-extraction code did) leaves a window in which a concurrent
 * activation changes the row between the check and the work.
 */
final class VerifiedAccountActivator
{
    public function __construct(
        private readonly SignupIntentRepository $intents,
        private readonly UserRepository $users,
        private readonly Connection $connection,
        private readonly TenantContextRunner $tenants,
    ) {
    }

    /**
     * @param callable(string,array<string,mixed>,string):void $afterIdentityCreated
     * @return array<string,mixed>
     */
    public function activate(
        string $intentUuid,
        string $continuationToken,
        string $purpose,
        callable $afterIdentityCreated,
    ): array {
        // The initial read establishes ONLY the tenant to run inside. Every decision — kind,
        // status, consumed outcome — is made under the row lock below. Deciding here would put
        // the purpose check outside the lock again, which is the window this extraction closes:
        // a concurrent activation can change kind or status between this read and the work.
        $preRead = $this->intents->find($intentUuid);
        if ($preRead === null) {
            throw new SignupException('Signup intent is unavailable.', 404);
        }
        $tenantUuid = (string) ($preRead['tenant_uuid'] ?? '');

        try {
            return $this->tenants->runAsTenant($tenantUuid, function () use (
                $intentUuid,
                $tenantUuid,
                $purpose,
                $afterIdentityCreated
            ): array {
                return $this->connection->transaction(function () use (
                    $intentUuid,
                    $tenantUuid,
                    $purpose,
                    $afterIdentityCreated
                ): array {
                    $intent = $this->intents->lockForUpdate($intentUuid);
                    if ($intent === null || ($intent['kind'] ?? null) !== $purpose) {
                        // THE boundary. A mismatched purpose is indistinguishable from a missing
                        // intent, and it is decided here — holding the row — so a concurrent
                        // activation cannot have changed the kind since it was read.
                        throw new SignupException('Signup intent is unavailable.', 404);
                    }
                    if (($intent['status'] ?? null) === 'consumed') {
                        // Idempotent replay: the recorded outcome, read under the lock so two
                        // concurrent activations agree on which one won.
                        return ['status' => 'consumed', 'outcome' => $intent['completion_outcome']];
                    }
                    if (($intent['status'] ?? null) !== 'email_verified') {
                        throw new SignupException('Signup intent cannot be activated.', 409);
                    }

                    $email = (string) $intent['email'];
                    if ($this->users->emailExists($email)) {
                        $this->intents->consume($intentUuid, 'existing_account_handoff');
                        return ['status' => 'consumed', 'outcome' => 'existing_account_handoff'];
                    }
                    $username = (string) $intent['username'];
                    if ($this->users->usernameExists($username)) {
                        throw new SignupException('Username is no longer available.', 409, [
                            'username' => 'Choose another username.',
                        ], 'USERNAME_CONFLICT');
                    }

                    $userUuid = $this->users->create([
                        'username' => $username,
                        'email' => $email,
                        'password' => (string) $intent['password_hash'],
                        'status' => 'active',
                        'email_verified_at' => gmdate('Y-m-d H:i:s'),
                    ]);
                    $this->users->updateProfile($userUuid, [
                        'first_name' => (string) $intent['first_name'],
                        'last_name' => (string) $intent['last_name'],
                    ]);

                    // Purpose-specific work INSIDE the same transaction: a failure here rolls
                    // the identity back rather than leaving an account with half its grants.
                    $afterIdentityCreated($userUuid, $intent, $tenantUuid);

                    $this->intents->setResults($intentUuid, $userUuid, $tenantUuid);
                    $this->intents->consume($intentUuid, 'activated');

                    return ['status' => 'active', 'user_uuid' => $userUuid, 'tenant_uuid' => $tenantUuid];
                });
            });
        } catch (SignupException $exception) {
            if ($exception->errorCode === 'USERNAME_CONFLICT') {
                return [
                    'status' => 'conflict',
                    'code' => 'USERNAME_CONFLICT',
                    'continuation_token' => $continuationToken,
                    'errors' => $exception->errors,
                ];
            }
            throw $exception;
        } catch (\Throwable $exception) {
            if ($this->users->emailExists((string) $preRead['email'])) {
                $this->intents->consume($intentUuid, 'existing_account_handoff');
                return ['status' => 'consumed', 'outcome' => 'existing_account_handoff'];
            }
            throw $exception;
        }
    }
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Signup;

use Glueful\Auth\PasswordHasher;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Extensions\Tenancy\Resolution\HostNormalizer;
use Glueful\Extensions\Users\Repositories\UserRepository;
use Thallo\Contracts\Tenancy\TenancyLifecycleAudit;
use Thallo\Tenancy\Contracts\TenantSeedActivator;
use Thallo\Tenancy\Contracts\TenantSeedRepair;
use Thallo\Tenancy\System\SystemFlags;

final class WorkspaceSignupService
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly Connection $connection,
        private readonly SignupConfig $config,
        private readonly SignupIntentRepository $intents,
        private readonly SignupVerifier $verifier,
        private readonly ContinuationTokens $continuations,
        private readonly SignupThrottle $throttle,
        private readonly SignupMailSender $mail,
        private readonly UserRepository $users,
        private readonly TenantAdministration $administration,
        private readonly TenantSeedActivator $seeder,
        private readonly TenantSeedRepair $repair,
        private readonly SystemFlags $flags,
        private readonly TenancyLifecycleAudit $audit,
    ) {
    }

    /** @param array<string,mixed> $input @return array{accepted:true,intent_uuid:string} */
    public function beginAnonymous(array $input, string $ip): array
    {
        $account = SignupInput::anonymous($input);
        $workspace = SignupInput::workspace($input);
        $this->assertWorkspaceTarget($workspace['slug']);
        if (!$this->throttle->allowIntent('workspace', $ip, $account['email'])) {
            throw new SignupException('Signup request limit reached.', 429);
        }
        $this->assertEnabled();
        $intentUuid = $this->intents->create([
            'kind' => 'workspace',
            'origin' => 'anonymous',
            'email' => $account['email'],
            'username' => $account['username'],
            'first_name' => $account['first_name'],
            'last_name' => $account['last_name'],
            'password_hash' => (new PasswordHasher())->hash($account['password']),
            'tenant_uuid' => null,
            'desired_slug' => $workspace['slug'],
            'workspace_name' => $workspace['name'],
            'result_user_uuid' => null,
            'result_tenant_uuid' => null,
            'request_ip_hash' => $this->throttle->hashIdentifier($ip),
            'expires_at' => $this->expiresAt(),
        ]);
        if ($this->users->emailExists($account['email'])) {
            try {
                $this->mail->sendExistingAccountNotice($intentUuid, $account['email']);
            } finally {
                $this->intents->consume($intentUuid, 'existing_account_handoff');
            }
            return ['accepted' => true, 'intent_uuid' => $intentUuid];
        }
        try {
            $this->verifier->issue($intentUuid, $account['email']);
        } catch (\Throwable $exception) {
            $this->intents->hardDelete($intentUuid);
            throw $exception;
        }
        return ['accepted' => true, 'intent_uuid' => $intentUuid];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function beginAuthenticated(array $input, string $ip, string $userUuid): array
    {
        $workspace = SignupInput::workspace($input);
        $this->assertWorkspaceTarget($workspace['slug']);
        $user = $this->users->findByUuid($userUuid);
        if ($user === null || ($user['email_verified_at'] ?? null) === null) {
            throw new SignupException('Verify your email before creating a workspace.', 403);
        }
        $email = strtolower((string) $user['email']);
        if (!$this->throttle->allowIntent('workspace', $ip, $email)) {
            throw new SignupException('Signup request limit reached.', 429);
        }
        $this->assertEnabled();
        $intentUuid = $this->intents->create([
            'kind' => 'workspace',
            'origin' => 'authenticated',
            'email' => $email,
            'username' => (string) $user['username'],
            'first_name' => null,
            'last_name' => null,
            'password_hash' => null,
            'tenant_uuid' => null,
            'desired_slug' => $workspace['slug'],
            'workspace_name' => $workspace['name'],
            'result_user_uuid' => $userUuid,
            'result_tenant_uuid' => null,
            'status' => 'email_verified',
            'request_ip_hash' => $this->throttle->hashIdentifier($ip),
            'expires_at' => $this->expiresAt(),
        ]);
        $token = $this->continuations->issue($intentUuid);
        return $this->provision($intentUuid, $token);
    }

    /** @return array<string,mixed> */
    public function provision(string $intentUuid, string $continuationToken): array
    {
        $this->assertEnabled();
        $intent = $this->intents->find($intentUuid);
        if ($intent === null || ($intent['kind'] ?? null) !== 'workspace') {
            throw new SignupException('Signup intent is unavailable.', 404);
        }
        if (($intent['status'] ?? null) === 'consumed') {
            return ['status' => 'consumed', 'outcome' => $intent['completion_outcome']];
        }

        $createdNow = false;
        if (($intent['status'] ?? null) === 'email_verified') {
            try {
                $intent = $this->createProvisioningResources($intentUuid);
                $createdNow = true;
            } catch (\Throwable $exception) {
                $latest = $this->intents->find($intentUuid) ?? $intent;
                $email = (string) ($latest['email'] ?? '');
                if ($email !== '' && $this->users->emailExists($email)) {
                    $this->intents->consume($intentUuid, 'existing_account_handoff');
                    return ['status' => 'consumed', 'outcome' => 'existing_account_handoff'];
                }
                $username = (string) ($latest['username'] ?? '');
                if ($username !== '' && $this->users->usernameExists($username)) {
                    return $this->conflict('USERNAME_CONFLICT', 'username', $continuationToken);
                }
                $slug = (string) ($latest['desired_slug'] ?? '');
                if ($slug !== '' && $this->slugExists($slug)) {
                    return $this->conflict('SLUG_CONFLICT', 'slug', $continuationToken);
                }
                throw $exception;
            }
        }

        $intent = $this->intents->find($intentUuid) ?? $intent;
        if (($intent['status'] ?? null) !== 'provisioning') {
            throw new SignupException('Signup intent cannot be provisioned.', 409);
        }
        $tenantUuid = (string) ($intent['result_tenant_uuid'] ?? '');
        $userUuid = (string) ($intent['result_user_uuid'] ?? '');
        if ($tenantUuid === '' || $userUuid === '') {
            throw new SignupException('Provisioning state is incomplete.', 409);
        }
        try {
            $tenant = $this->administration->getTenant($this->context, $tenantUuid);
            if (($tenant['status'] ?? null) === 'active') {
                // A previous attempt crossed the seed boundary before its response was lost.
            } elseif ($createdNow) {
                $this->seeder->seedAndActivate($tenantUuid, $userUuid);
            } else {
                $this->repair->repair($tenantUuid);
            }
        } catch (\Throwable $exception) {
            return [
                'status' => 'provisioning',
                'tenant_uuid' => $tenantUuid,
                'continuation_token' => $continuationToken,
                'message' => $exception->getMessage(),
            ];
        }
        $this->intents->consume($intentUuid, 'workspace_active');
        $this->audit->record('signup.workspace_provisioned', $userUuid, $tenantUuid, [
            'intent_uuid' => $intentUuid,
        ]);
        return ['status' => 'active', 'tenant_uuid' => $tenantUuid, 'user_uuid' => $userUuid];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function continue(
        string $intentUuid,
        string $token,
        string $operationId,
        string $operation,
        array $payload,
    ): array {
        $canonical = $payload;
        ksort($canonical);
        $payloadHash = hash('sha256', json_encode(
            ['operation' => $operation, 'payload' => $canonical],
            JSON_THROW_ON_ERROR,
        ));
        try {
            $result = $this->connection->transaction(function () use (
                $intentUuid,
                $token,
                $operationId,
                $operation,
                $payload,
                $payloadHash,
            ): array {
                $grant = $this->continuations->authorizeInTransaction(
                    $intentUuid,
                    $token,
                    $operationId,
                    $payloadHash,
                );
                if ($grant->replay) {
                    return ($grant->result ?? []) + ['continuation_token' => $grant->token];
                }
                if ($operation === 'change_slug') {
                    $workspace = SignupInput::workspace([
                        'slug' => $payload['slug'] ?? '',
                        'name' => $payload['name'] ?? ($this->intents->find($intentUuid)['workspace_name'] ?? ''),
                    ]);
                    $this->assertWorkspaceTarget($workspace['slug']);
                    if ($this->slugExists($workspace['slug'])) {
                        throw new SignupException('Workspace slug is no longer available.', 409);
                    }
                    $this->intents->updateWorkspaceTarget($intentUuid, $workspace['slug'], $workspace['name']);
                    $value = ['status' => 'updated'];
                    $this->continuations->completeInTransaction($intentUuid, $operationId, $value);
                    return $value + ['continuation_token' => $grant->token];
                }
                if ($operation === 'change_username') {
                    try {
                        $username = \Glueful\DTOs\UsernameDTO::from([
                            'username' => $payload['username'] ?? '',
                        ])->username;
                    } catch (\Glueful\Validation\ValidationException) {
                        throw new SignupException('Username is invalid.', 422);
                    }
                    if ($this->users->usernameExists($username)) {
                        throw new SignupException('Username is no longer available.', 409);
                    }
                    $this->intents->updateUsername($intentUuid, $username);
                    $value = ['status' => 'updated'];
                    $this->continuations->completeInTransaction($intentUuid, $operationId, $value);
                    return $value + ['continuation_token' => $grant->token];
                }
                if ($operation !== 'resume') {
                    throw new SignupException('Unknown continuation operation.', 422);
                }
                return ['status' => 'resume', 'continuation_token' => $grant->token];
            });
        } catch (SignupException $exception) {
            if ($exception->errorCode === 'CONTINUATION_REJECTED') {
                $this->continuations->invalidate($intentUuid);
            }
            throw $exception;
        }
        if (($result['status'] ?? null) === 'resume') {
            return $this->provision($intentUuid, (string) $result['continuation_token']);
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private function createProvisioningResources(string $intentUuid): array
    {
        return $this->connection->transaction(function () use ($intentUuid): array {
            $intent = $this->intents->lockForUpdate($intentUuid);
            if ($intent === null || ($intent['status'] ?? null) !== 'email_verified') {
                throw new SignupException('Signup intent cannot enter provisioning.', 409);
            }
            $origin = (string) $intent['origin'];
            $userUuid = (string) ($intent['result_user_uuid'] ?? '');
            if ($origin === 'anonymous') {
                if ($this->users->emailExists((string) $intent['email'])) {
                    throw new SignupException('Account already exists.', 409, [], 'ACCOUNT_EXISTS');
                }
                if ($this->users->usernameExists((string) $intent['username'])) {
                    throw new SignupException('Username is no longer available.', 409, [], 'USERNAME_CONFLICT');
                }
                $userUuid = $this->users->create([
                    'username' => (string) $intent['username'],
                    'email' => (string) $intent['email'],
                    'password' => (string) $intent['password_hash'],
                    'status' => 'active',
                    'email_verified_at' => gmdate('Y-m-d H:i:s'),
                ]);
                $this->users->updateProfile($userUuid, [
                    'first_name' => (string) $intent['first_name'],
                    'last_name' => (string) $intent['last_name'],
                ]);
            }
            $tenantUuid = $this->administration->create(
                $this->context,
                (string) $intent['desired_slug'],
                (string) $intent['workspace_name'],
                $userUuid,
            );
            $this->intents->setResults($intentUuid, $userUuid, $tenantUuid);
            if (!$this->intents->transition($intentUuid, 'email_verified', 'provisioning')) {
                throw new SignupException('Signup intent changed concurrently.', 409);
            }
            return $this->intents->find($intentUuid)
                ?? throw new SignupException('Provisioning intent disappeared.', 409);
        });
    }

    private function assertEnabled(): void
    {
        if (!$this->config->workspaceSignupEnabled() || !$this->config->emailChannelAvailable()) {
            throw new SignupException('Workspace signup is unavailable.', 503);
        }
    }

    private function assertWorkspaceTarget(string $slug): void
    {
        /** @var array<string,mixed> $origin */
        $origin = (array) config($this->context, 'tenancy.public_origin', []);
        $base = $origin['base_domain'] ?? null;
        if (is_string($base) && trim($base) !== '') {
            HostNormalizer::validateForRegistration(HostNormalizer::normalize($slug . '.' . $base), $origin);
            return;
        }
        $reserved = is_array($origin['reserved_labels'] ?? null) ? $origin['reserved_labels'] : [];
        if (in_array($slug, $reserved, true)) {
            throw new SignupException('Workspace slug is reserved.', 422, ['slug' => 'Choose another slug.']);
        }
    }

    private function slugExists(string $slug): bool
    {
        return $this->connection->table('tenants')->where('slug', '=', $slug)->first() !== null;
    }

    /** @return array<string,mixed> */
    private function conflict(string $code, string $field, string $token): array
    {
        return [
            'status' => 'conflict',
            'code' => $code,
            'continuation_token' => $token,
            'errors' => [$field => 'Choose another ' . str_replace('_', ' ', $field) . '.'],
        ];
    }

    private function expiresAt(): string
    {
        $ttl = max(300, (int) config($this->context, 'signup.intent_ttl_seconds', 86400));
        return gmdate('Y-m-d H:i:s', time() + $ttl);
    }
}

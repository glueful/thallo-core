<?php

declare(strict_types=1);

namespace Thallo\Core\Signup;

use Glueful\Auth\PasswordHasher;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Users\Repositories\UserRepository;
use Thallo\Contracts\Tenancy\TenancyLifecycleAudit;
use Thallo\Tenancy\Tenant\SingleStoreTenant;

/**
 * Storefront customer registration.
 *
 * The shopper receives a global Glueful identity and nothing else — no workspace membership, no
 * role, no permission. That is structural, not a rule to remember: `activate()` passes a
 * continuation that does nothing, so there is no code path from here to `addMember()`.
 *
 * The username IS the email. Both columns are `varchar(255)` (framework ≥ 1.74.0), email
 * uniqueness is enforced one check below, so there is no derivation, no collision handling and no
 * retry. A shopper who wants a different username changes it from their account later.
 */
final class CustomerSignupService
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly Connection $connection,
        private readonly SingleStoreTenant $singleStore,
        private readonly SignupConfig $config,
        private readonly SignupIntentRepository $intents,
        private readonly VerifiedAccountActivator $activator,
        private readonly SignupVerifier $verifier,
        private readonly SignupThrottle $throttle,
        private readonly SignupMailSender $mail,
        private readonly UserRepository $users,
        private readonly TenancyLifecycleAudit $audit,
    ) {
    }

    /**
     * Every path returns `['accepted' => true, 'intent_uuid' => ...]`. An already-registered
     * address gets the existing-account notice and a consumed intent, exactly as member signup
     * does, so the response cannot be used to test whether an email is registered. When the email
     * channel is unavailable the uuid is opaque rather than real, which is indistinguishable to
     * the caller.
     *
     * @param array<string,mixed> $input {email, password, first_name, last_name}
     * @return array{accepted: true, intent_uuid: string}
     */
    public function begin(array $input, string $ip): array
    {
        $data = SignupInput::customer($input);
        $email = $data['email'];
        $password = $data['password'];

        if (!$this->throttle->allowIntent('customer', $ip, $email)) {
            throw new SignupException('Signup request limit reached.', 429);
        }
        if (!$this->config->emailChannelAvailable()) {
            // No channel means no OTP can ever arrive; the opaque id keeps that indistinguishable.
            return ['accepted' => true, 'intent_uuid' => $this->opaqueRequestId()];
        }

        $tenantUuid = $this->singleStore->resolve();
        $intentUuid = $this->intents->create([
            'kind' => 'customer',
            'origin' => 'anonymous',
            'email' => $email,
            // The username IS the email. Email uniqueness is enforced by the check below, so this
            // needs no derivation, no collision handling and no retry.
            'username' => $email,
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'password_hash' => (new PasswordHasher())->hash($password),
            'tenant_uuid' => $tenantUuid,
            'desired_slug' => null,
            'workspace_name' => null,
            'result_user_uuid' => null,
            'result_tenant_uuid' => null,
            'request_ip_hash' => $this->throttle->hashIdentifier($ip),
            'expires_at' => $this->expiresAt(),
        ]);

        if ($this->users->emailExists($email)) {
            try {
                $this->mail->sendExistingAccountNotice($intentUuid, $email);
            } finally {
                $this->intents->consume($intentUuid, 'existing_account_handoff');
            }

            return ['accepted' => true, 'intent_uuid' => $intentUuid];
        }

        try {
            $this->verifier->issue($intentUuid, $email);
        } catch (\Throwable $exception) {
            $this->intents->hardDelete($intentUuid);
            throw $exception;
        }

        return ['accepted' => true, 'intent_uuid' => $intentUuid];
    }

    /** @return array<string,mixed> */
    public function activate(string $intentUuid, string $continuationToken): array
    {
        // No continuation: a shopper receives identity and nothing else. There is deliberately no
        // branch here that could reach addMember(), and no retry loop — the username is the email,
        // which the emailExists() check has already established is unclaimed.
        return $this->activator->activate(
            $intentUuid,
            $continuationToken,
            'customer',
            function (string $userUuid, array $intent, string $tenantUuid) use ($intentUuid): void {
                $this->connection->afterCommit(fn () => $this->audit->record(
                    'signup.customer_activated',
                    $userUuid,
                    $tenantUuid,
                    ['intent_uuid' => $intentUuid],
                ));
            },
        );
    }

    private function expiresAt(): string
    {
        $ttl = max(300, (int) config($this->context, 'signup.intent_ttl_seconds', 86400));

        return gmdate('Y-m-d H:i:s', time() + $ttl);
    }

    private function opaqueRequestId(): string
    {
        return \Glueful\Helpers\Utils::generateNanoID(12);
    }
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Signup;

use Glueful\Database\Connection;

final class SignupCoordinator
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SignupIntentRepository $intents,
        private readonly SignupVerifier $verifier,
        private readonly ContinuationTokens $continuations,
        private readonly SignupThrottle $throttle,
        private readonly MemberSignupService $members,
        private readonly CustomerSignupService $customers,
        private readonly WorkspaceSignupService $workspaces,
    ) {
    }

    /** @return array<string,mixed> */
    public function verify(string $intentUuid, string $otp): array
    {
        $result = $this->connection->transaction(function () use ($intentUuid, $otp): array {
            if (!$this->verifier->verify($intentUuid, $otp)) {
                return ['failed' => true];
            }
            $intent = $this->intents->lockForUpdate($intentUuid);
            if ($intent === null) {
                throw new SignupException('Signup intent is unavailable.', 404);
            }
            if (($intent['status'] ?? null) === 'pending') {
                if (!$this->intents->transition($intentUuid, 'pending', 'email_verified')) {
                    throw new SignupException('Signup intent changed concurrently.', 409);
                }
                $intent['status'] = 'email_verified';
            } elseif (($intent['status'] ?? null) !== 'email_verified') {
                throw new SignupException('Signup intent cannot be verified.', 409);
            }
            return ['intent' => $intent, 'token' => $this->continuations->issue($intentUuid)];
        });
        if (($result['failed'] ?? false) === true) {
            throw new SignupException('Verification code is invalid or expired.', 422, [
                'code' => 'Check the code and try again.',
            ]);
        }
        /** @var array<string,mixed> $intent */
        $intent = $result['intent'];
        $token = (string) $result['token'];
        return match ($intent['kind'] ?? null) {
            'member' => $this->members->activate($intentUuid, $token),
            'customer' => $this->customers->activate($intentUuid, $token),
            'workspace' => $this->workspaces->provision($intentUuid, $token),
            // An unknown kind must not fall through to whichever branch happens to be last.
            default => throw new SignupException('Signup intent is unavailable.', 404),
        };
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function continue(
        string $intentUuid,
        string $token,
        string $operationId,
        string $operation,
        array $payload,
    ): array {
        $intent = $this->intents->find($intentUuid);
        if ($intent === null) {
            throw new SignupException('Signup intent is unavailable.', 404);
        }
        return match ($intent['kind'] ?? null) {
            'member' => $this->members->continue($intentUuid, $token, $operationId, $operation, $payload),
            'workspace' => $this->workspaces->continue($intentUuid, $token, $operationId, $operation, $payload),
            // Customers have no username-change operation, and an unknown kind must not fall
            // through to whichever branch happens to be last.
            default => throw new SignupException('Signup intent is unavailable.', 404),
        };
    }

    /** @return array{accepted:true} */
    public function reverify(string $intentUuid, string $ip): array
    {
        $intent = $this->intents->find($intentUuid);
        if ($intent === null || !is_string($intent['email'] ?? null)) {
            return ['accepted' => true];
        }
        if (!$this->throttle->allowResend($intentUuid, $intent['email'], $ip)) {
            throw new SignupException('Verification request limit reached.', 429);
        }
        $this->continuations->resetForReverification($intentUuid);
        $this->verifier->issue($intentUuid, $intent['email']);
        return ['accepted' => true];
    }
}

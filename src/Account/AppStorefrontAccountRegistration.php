<?php

declare(strict_types=1);

namespace Thallo\Core\Account;

use Thallo\Core\Signup\CustomerSignupService;
use Thallo\Core\Signup\SignupCoordinator;
use Psr\Log\LoggerInterface;
use Thallo\Contracts\Account\RegistrationResult;
use Thallo\Contracts\Account\StorefrontAccountRegistration;

/**
 * Storefront registration over the app's signup pipeline. This is the ONE class allowed to name
 * `Thallo\Core\Signup`; the account pack consumes the contract and never imports it.
 */
final class AppStorefrontAccountRegistration implements StorefrontAccountRegistration
{
    public function __construct(
        private readonly CustomerSignupService $customers,
        private readonly SignupCoordinator $coordinator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function begin(
        string $email,
        string $password,
        string $firstName,
        string $lastName,
        string $ip,
    ): RegistrationResult {
        try {
            $result = $this->customers->begin([
                'email' => $email,
                'password' => $password,
                'first_name' => $firstName,
                'last_name' => $lastName,
            ], $ip);
            $intentUuid = is_string($result['intent_uuid'] ?? null) ? (string) $result['intent_uuid'] : null;
        } catch (\Throwable $exception) {
            // The storefront-neutrality boundary, symmetric with AppStorefrontAccountRecovery::begin:
            // a throttle limit, a delivery failure, a malformed field and an already-registered
            // address all collapse to the SAME pending result. A caller can never use the response
            // to probe whether an address is registered; the operator sees the cause in the log.
            $this->logger->warning('Storefront registration request failed', ['error' => $exception->getMessage()]);
            $intentUuid = null;
        }

        // Always pending: the identity does not exist until the OTP is verified. The uuid is
        // opaque (a real intent, or null when the request could not be recorded) — never a signal.
        return new RegistrationResult(
            pendingVerification: true,
            intentUuid: $intentUuid,
            userUuid: null,
        );
    }

    public function resend(string $intentUuid, string $ip): void
    {
        $this->coordinator->reverify($intentUuid, $ip);
    }

    public function verify(string $intentUuid, string $otp): RegistrationResult
    {
        $result = $this->coordinator->verify($intentUuid, $otp);
        $userUuid = isset($result['user_uuid']) ? (string) $result['user_uuid'] : null;

        return new RegistrationResult(
            pendingVerification: false,
            intentUuid: $intentUuid,
            userUuid: $userUuid,
        );
    }
}

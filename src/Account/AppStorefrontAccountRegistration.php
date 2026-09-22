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
        /**
         * Opens a session for a verified account: fn(string $userUuid): ?array. Null (no users
         * extension) leaves the customer to sign in.
         *
         * @var (\Closure(string): ?array<string,mixed>)|null
         */
        private readonly ?\Closure $sessionFor = null,
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
            session: $userUuid === null ? null : $this->session($userUuid),
        );
    }

    /**
     * The new customer has just proved the address and chosen the password, so they are signed
     * in; a failure here only sends them to the sign-in page.
     *
     * @return array<string,mixed>|null
     */
    private function session(string $userUuid): ?array
    {
        if ($this->sessionFor === null) {
            return null;
        }
        try {
            $session = ($this->sessionFor)($userUuid);
        } catch (\Throwable $e) {
            $this->logger->warning('A verified customer could not be signed in', ['error' => $e->getMessage()]);
            return null;
        }
        return is_array($session) && $session !== [] ? $session : null;
    }
}

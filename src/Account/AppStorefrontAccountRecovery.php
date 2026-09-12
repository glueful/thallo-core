<?php

declare(strict_types=1);

namespace Thallo\Core\Account;

use Glueful\Auth\Interfaces\SessionStoreInterface;
use Glueful\Auth\PasswordHasher;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Users\Repositories\UserRepository;
use Glueful\Extensions\Users\Services\EmailVerification;
use Psr\Log\LoggerInterface;
use Thallo\Contracts\Account\RecoveryResult;
use Thallo\Contracts\Account\RecoveryVerification;
use Thallo\Contracts\Account\StorefrontAccountRecovery;

/**
 * Storefront recovery over `glueful/users`' `EmailVerification`, collapsing every outcome into
 * the neutral contract results.
 *
 * `begin()` swallows unknown-address, throttle and delivery failures alike — the operator sees
 * them in the log, the visitor sees "check your email" either way. `verify()` returns
 * `verified: false` for both a wrong code and an unknown address. `complete()` is the one method
 * that may return `accepted: false`: an invalid or replayed token is a fact about the token in
 * hand, not an enumeration signal.
 */
final class AppStorefrontAccountRecovery implements StorefrontAccountRecovery
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly UserRepository $users,
        private readonly SessionStoreInterface $sessions,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function begin(string $email, string $ip): RecoveryResult
    {
        try {
            // sendPasswordResetEmail() returns an error ARRAY for unknown/rate-limited/invalid and
            // may throw on transport failure. Neither distinction may reach the caller.
            EmailVerification::sendPasswordResetEmail($email, $this->context);
        } catch (\Throwable $e) {
            $this->logger->warning('Storefront recovery request failed', ['error' => $e->getMessage()]);
        }

        return new RecoveryResult(accepted: true);
    }

    public function verify(string $email, string $otp): RecoveryVerification
    {
        // Returns null for a bad code AND for an unknown address, so this leaks nothing either.
        // The payload key is `reset_token` (EmailVerification returns ['reset_token' => ...]).
        $payload = (new EmailVerification(context: $this->context))->verifyPasswordResetOTP($email, $otp);
        if ($payload === null || !is_string($payload['reset_token'] ?? null)) {
            return new RecoveryVerification(verified: false, resetToken: null);
        }

        return new RecoveryVerification(verified: true, resetToken: (string) $payload['reset_token']);
    }

    public function complete(string $resetToken, string $newPassword): RecoveryResult
    {
        // consumePasswordResetToken() is single-use (setNx): a replayed token returns null, so a
        // leaked link cannot be used twice.
        $reset = (new EmailVerification(context: $this->context))->consumePasswordResetToken($resetToken);
        if ($reset === null) {
            return new RecoveryResult(accepted: false);
        }

        // setNewPassword() takes a PRE-HASHED password — its docblock says so and it writes the
        // value straight to the column. 'uuid' is passed so a uuid is never sniffed as an email.
        // PasswordHasher is instantiated directly, matching the signup services — it is not a
        // container-bound service.
        $written = $this->users->setNewPassword(
            (string) $reset['user_uuid'],
            (new PasswordHasher())->hash($newPassword),
            'uuid',
        );
        if ($written !== true) {
            // Token already consumed but the password did not change: reporting success would
            // strand the visitor with old credentials and a dead link.
            $this->logger->error('Storefront recovery could not write the new password', [
                'user_uuid' => (string) $reset['user_uuid'],
            ]);

            return new RecoveryResult(accepted: false);
        }

        // Only after a CONFIRMED write: revoking first would log the visitor out of a password
        // they still have. Whoever forced the reset must lose the access it exists to revoke.
        $this->sessions->revokeAllForUser((string) $reset['user_uuid']);

        return new RecoveryResult(accepted: true);
    }
}

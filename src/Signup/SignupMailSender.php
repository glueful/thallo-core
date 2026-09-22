<?php

declare(strict_types=1);

namespace Thallo\Core\Signup;

use Glueful\Notifications\Services\NotificationService;
use Thallo\Core\Support\EmailDelivery;

final class SignupMailSender
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function sendVerification(string $intentUuid, string $email, string $otp, int $ttlSeconds): void
    {
        $result = $this->notifications->send(
            'signup_verification',
            new SignupEmailRecipient($email),
            'Verify your email address',
            [
                'template_name' => 'verification',
                'otp' => $otp,
                'expiry_minutes' => max(1, (int) ceil($ttlSeconds / 60)),
            ],
            [
                'channels' => ['email'],
                'idempotency_key' => 'signup-verification:' . $intentUuid,
            ],
        );
        $this->assertSent($result);
    }

    public function sendExistingAccountNotice(string $intentUuid, string $email): void
    {
        $result = $this->notifications->send(
            'signup_existing_account',
            new SignupEmailRecipient($email),
            'Sign in to continue',
            [
                'template_name' => 'default',
                'message' => 'An account already exists for this email. Sign in to continue.',
            ],
            [
                'channels' => ['email'],
                'idempotency_key' => 'signup-existing:' . $intentUuid,
            ],
        );
        $this->assertSent($result);
    }

    /** @param array<string,mixed> $result */
    private function assertSent(array $result): void
    {
        if (!EmailDelivery::delivered($result)) {
            throw new SignupException('Verification email could not be delivered.', 503);
        }
    }
}

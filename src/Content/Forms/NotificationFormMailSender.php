<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Forms;

use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Services\NotificationService;

/**
 * Form notifications over the notification service's email channel — the path signup's mail
 * already takes. Bound by default: nothing implemented FormMailSender before, so the form block
 * promised an email the default install never sent.
 *
 * A send the email channel did not deliver THROWS: the notifier then reports the notification as
 * not sent, and an "email only" form keeps the submission instead of losing it.
 */
final class NotificationFormMailSender implements FormMailSender
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function send(string $to, string $subject, string $body): void
    {
        $recipient = new class ($to) implements Notifiable {
            public function __construct(private readonly string $email)
            {
            }

            public function routeNotificationFor(string $channel): ?string
            {
                return $channel === 'email' ? $this->email : null;
            }

            public function getNotifiableId(): string
            {
                return hash('sha256', strtolower($this->email));
            }

            public function getNotifiableType(): string
            {
                return 'form_recipient';
            }

            public function shouldReceiveNotification(string $notificationType, string $channel): bool
            {
                return $channel === 'email';
            }

            /** @return array{email:true} */
            public function getNotificationPreferences(): array
            {
                return ['email' => true];
            }
        };

        $result = $this->notifications->send(
            'form_submission',
            $recipient,
            $subject,
            ['template_name' => 'default', 'message' => $body],
            ['channels' => ['email']],
        );
        $email = is_array($result['channels']['email'] ?? null) ? $result['channels']['email'] : [];
        if (($email['status'] ?? null) !== 'success') {
            throw new \RuntimeException('The form notification could not be delivered by email.');
        }
    }
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Signup;

use Glueful\Notifications\Contracts\Notifiable;

final class SignupEmailRecipient implements Notifiable
{
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
        return 'signup_recipient';
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
}

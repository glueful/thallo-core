<?php

declare(strict_types=1);

namespace Thallo\Core\Account;

use Glueful\Extensions\Contracts\Email\EmailTemplateRegistry;
use Thallo\Contracts\Account\AccountMailTemplates;

/**
 * Which template a customer's mail goes out through: the account pack's own while it is
 * registered (the capability is on and the mail extension is present), else the built-in one.
 * Naming an unregistered template would fail the send, so the choice is made per send.
 */
final class AccountMailTemplateChooser
{
    public function __construct(private readonly ?EmailTemplateRegistry $registry = null)
    {
    }

    public function verification(): string
    {
        return $this->registered(AccountMailTemplates::VERIFICATION)
            ? AccountMailTemplates::VERIFICATION
            : 'verification';
    }

    public function passwordReset(): string
    {
        return $this->registered(AccountMailTemplates::PASSWORD_RESET)
            ? AccountMailTemplates::PASSWORD_RESET
            : 'password-reset';
    }

    private function registered(string $key): bool
    {
        return $this->registry?->find($key) !== null;
    }
}

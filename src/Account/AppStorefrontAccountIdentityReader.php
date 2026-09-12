<?php

declare(strict_types=1);

namespace Thallo\Core\Account;

use Glueful\Auth\Contracts\UserProviderInterface;
use Thallo\Contracts\Account\StorefrontAccountIdentityReader;

/**
 * The app's {@see StorefrontAccountIdentityReader}: a thin read over the framework's
 * {@see UserProviderInterface} (the users extension binds it), so packs can prefill a signed-in
 * visitor's email without naming an app or extension class. Fail-soft by contract: any lookup
 * failure is an anonymous render, never a 500 on a public page.
 */
final class AppStorefrontAccountIdentityReader implements StorefrontAccountIdentityReader
{
    public function __construct(private readonly UserProviderInterface $users)
    {
    }

    public function emailFor(string $userUuid): ?string
    {
        if (trim($userUuid) === '') {
            return null;
        }

        try {
            $email = $this->users->findByUuid($userUuid)?->email();
        } catch (\Throwable) {
            return null;
        }

        return is_string($email) && trim($email) !== '' ? trim($email) : null;
    }
}

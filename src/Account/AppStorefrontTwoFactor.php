<?php

declare(strict_types=1);

namespace Thallo\Core\Account;

use Thallo\Contracts\Account\StorefrontTwoFactor;

/**
 * StorefrontTwoFactor over the users extension's two-factor service. Its verify() answers both
 * login and enrollment challenges; only a login challenge may issue a session here, so an
 * enrollment token can never sign a visitor in. Every failure (wrong or expired code, forged or
 * reused token, two-factor switched off) reads the same: null.
 */
final class AppStorefrontTwoFactor implements StorefrontTwoFactor
{
    /**
     * @param (\Closure(string, string): array<string, mixed>)|null $verify the users extension's
     *        TwoFactorService::verify, or null when two-factor is not available
     */
    public function __construct(private readonly ?\Closure $verify)
    {
    }

    public function completeLogin(string $challengeToken, string $code): ?array
    {
        if ($this->verify === null || $challengeToken === '' || $code === '') {
            return null;
        }

        try {
            $result = ($this->verify)($challengeToken, $code);
        } catch (\Throwable) {
            return null;
        }

        if (($result['purpose'] ?? null) !== 'login' || !is_array($result['session'] ?? null)) {
            return null;
        }

        return $result['session'];
    }
}

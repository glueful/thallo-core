<?php

declare(strict_types=1);

namespace Thallo\Core\Setup;

/**
 * The first admin's password rules, the same the web setup form shows as you type
 * (admin/src/pages/setup.vue). Checked on the server for both the web form and
 * `thallo:create-admin`, so neither path can seed the only privileged account with less.
 */
final class AdminPasswordPolicy
{
    /** @return list<string> every rule the password breaks; empty when it passes */
    public static function problems(string $password): array
    {
        $rules = [
            'Must be at least 8 characters' => strlen($password) >= 8,
            'At least 1 number' => preg_match('/\d/', $password) === 1,
            'At least 1 lowercase letter' => preg_match('/[a-z]/', $password) === 1,
            'At least 1 uppercase letter' => preg_match('/[A-Z]/', $password) === 1,
            'At least 1 special character' => preg_match('/[!@#$%^&*(),.?":{}|<>]/', $password) === 1,
            'Cannot contain "1234"' => !str_contains($password, '1234'),
            'No whitespace allowed' => preg_match('/\s/', $password) !== 1,
        ];

        return array_keys(array_filter($rules, static fn(bool $passes): bool => !$passes));
    }
}

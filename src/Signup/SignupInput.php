<?php

declare(strict_types=1);

namespace Thallo\Core\Signup;

use Glueful\DTOs\EmailDTO;
use Glueful\DTOs\UsernameDTO;
use Glueful\Validation\ValidationException;

final class SignupInput
{
    /**
     * Storefront customer registration input.
     *
     * `anonymous()` cannot be reused: it validates a `UsernameDTO`, and a shopper never supplies
     * a username. `customer()` validates exactly what the storefront form collects — email,
     * password, and first/last name — so registration input still passes through one validator
     * rather than ad-hoc string handling in the service. The username is derived from the email
     * downstream; it is never a form field.
     *
     * @param array<string,mixed> $input {email, password, first_name, last_name}
     * @return array{email:string,password:string,first_name:string,last_name:string}
     */
    public static function customer(array $input): array
    {
        $errors = [];
        try {
            $email = strtolower(EmailDTO::from(['email' => $input['email'] ?? ''])->email);
        } catch (ValidationException) {
            $email = '';
            $errors['email'] = 'Enter a valid email address.';
        }
        $password = is_string($input['password'] ?? null) ? $input['password'] : '';
        if (strlen($password) < 8) {
            $errors['password'] = 'Password must contain at least 8 characters.';
        }
        // First and last name are separate fields, each run through the same name() helper the
        // member form uses, so one validator owns the shape.
        $first = self::name($input['first_name'] ?? null, 'first_name', $errors);
        $last = self::name($input['last_name'] ?? null, 'last_name', $errors);
        if ($errors !== []) {
            throw new SignupException('Signup details are invalid.', 422, $errors);
        }

        return [
            'email' => $email,
            'password' => $password,
            'first_name' => $first,
            'last_name' => $last,
        ];
    }

    /** @param array<string,mixed> $input @return array<string,string> */
    public static function anonymous(array $input): array
    {
        $errors = [];
        try {
            $email = strtolower(EmailDTO::from(['email' => $input['email'] ?? ''])->email);
        } catch (ValidationException) {
            $email = '';
            $errors['email'] = 'Enter a valid email address.';
        }
        try {
            $username = UsernameDTO::from(['username' => $input['username'] ?? ''])->username;
        } catch (ValidationException) {
            $username = '';
            $errors['username'] = 'Username must contain 3 to 30 characters.';
        }
        $password = is_string($input['password'] ?? null) ? $input['password'] : '';
        if (strlen($password) < 8) {
            $errors['password'] = 'Password must contain at least 8 characters.';
        }
        $first = self::name($input['first_name'] ?? null, 'first_name', $errors);
        $last = self::name($input['last_name'] ?? null, 'last_name', $errors);
        if ($errors !== []) {
            throw new SignupException('Signup details are invalid.', 422, $errors);
        }
        return [
            'email' => $email,
            'username' => $username,
            'password' => $password,
            'first_name' => $first,
            'last_name' => $last,
        ];
    }

    /** @param array<string,mixed> $input @return array{slug:string,name:string} */
    public static function workspace(array $input): array
    {
        $slug = strtolower(trim(is_string($input['slug'] ?? null) ? $input['slug'] : ''));
        $name = trim(is_string($input['name'] ?? null) ? $input['name'] : '');
        $errors = [];
        if (preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $slug) !== 1) {
            $errors['slug'] = 'Use lowercase letters, numbers, and interior hyphens.';
        }
        if ($name === '' || strlen($name) > 160) {
            $errors['name'] = 'Workspace name is required and must not exceed 160 characters.';
        }
        if ($errors !== []) {
            throw new SignupException('Workspace details are invalid.', 422, $errors);
        }
        return ['slug' => $slug, 'name' => $name];
    }

    /** @param array<string,string> $errors */
    private static function name(mixed $value, string $field, array &$errors): string
    {
        $name = trim(is_string($value) ? $value : '');
        if ($name === '' || strlen($name) > 100) {
            $errors[$field] = 'This name is required and must not exceed 100 characters.';
        }
        return $name;
    }
}

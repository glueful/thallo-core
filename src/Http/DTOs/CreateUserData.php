<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Request body for `POST /v1/admin/users` ({@see \Thallo\Core\Http\Controllers\UserAdminController::store()}).
 *
 * Hydrated + format-validated by the router. Uniqueness (email/username already taken) is a domain
 * check the controller runs against the user store (→ 422). `role_slugs` are assigned via Aegis after
 * the account is created (the app composes the two extensions).
 *
 * @param list<string> $role_slugs
 */
final class CreateUserData implements RequestData
{
    public function __construct(
        #[Rule('required|string|min:1')]
        public readonly string $username,
        #[Rule('required|email')]
        public readonly string $email,
        #[Rule('required|string|min:8')]
        public readonly string $password,
        #[Rule('string')]
        public readonly ?string $first_name = null,
        #[Rule('string')]
        public readonly ?string $last_name = null,
        #[Rule('array')]
        public readonly array $role_slugs = [],
    ) {
    }
}

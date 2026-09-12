<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Request body for `PATCH /v1/admin/users/{uuid}` ({@see \Thallo\Core\Http\Controllers\UserAdminController::update()}).
 *
 * Every field is optional (partial update) — only the supplied keys are changed. Password is NOT
 * editable here (it has its own audited reset flow). `role_slugs` is nullable on purpose: omit it to
 * leave roles untouched, or send the full desired set (even `[]`) to replace them via Aegis.
 *
 * @param list<string>|null $role_slugs
 */
final class UpdateUserData implements RequestData
{
    public function __construct(
        #[Rule('string|min:1')]
        public readonly ?string $username = null,
        #[Rule('email')]
        public readonly ?string $email = null,
        #[Rule('string')]
        public readonly ?string $status = null,
        #[Rule('string')]
        public readonly ?string $first_name = null,
        #[Rule('string')]
        public readonly ?string $last_name = null,
        #[Rule('array')]
        public readonly ?array $role_slugs = null,
    ) {
    }
}

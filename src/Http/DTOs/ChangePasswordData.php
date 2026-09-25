<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Request body for `POST /v1/admin/account/password`
 * ({@see \Thallo\Core\Http\Controllers\AccountAdminController::password()}): the current password
 * and the new one. The new password's rules are checked by the controller, so a refusal names the
 * field it is about.
 */
final class ChangePasswordData implements RequestData
{
    public function __construct(
        #[Rule('string')]
        public readonly string $current_password = '',
        #[Rule('string')]
        public readonly string $password = '',
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Request body for `PATCH /v1/admin/account` ({@see \Thallo\Core\Http\Controllers\AccountAdminController::update()}):
 * the signed-in user's own profile. Every field is optional — an absent one is left as it is, an
 * empty string clears it. Email and username are not editable here.
 */
final class UpdateAccountData implements RequestData
{
    public function __construct(
        #[Rule('string')]
        public readonly ?string $first_name = null,
        #[Rule('string')]
        public readonly ?string $last_name = null,
        #[Rule('string')]
        public readonly ?string $photo_url = null,
    ) {
    }
}

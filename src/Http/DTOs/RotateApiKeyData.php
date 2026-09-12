<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Request body for `POST /v1/admin/api-keys/{uuid}/rotate`
 * ({@see \Thallo\Core\Http\Controllers\ApiKeyAdminController::rotate()}).
 *
 * `grace_hours` is the window the old key keeps working after rotation; the controller clamps it to
 * 1..720 (default 24).
 */
final class RotateApiKeyData implements RequestData
{
    public function __construct(
        #[Rule('numeric')]
        public readonly ?int $grace_hours = null,
    ) {
    }
}

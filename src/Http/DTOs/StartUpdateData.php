<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Request body for `POST /v1/admin/updates`
 * ({@see \Thallo\Core\Http\Controllers\AdminUpdateController::start()}).
 *
 * `target`: the version the confirmation dialog showed. The request is refused (409
 * `target_changed`) when preflight now offers a different one, so only what was confirmed is filed.
 */
final class StartUpdateData implements RequestData
{
    public function __construct(
        #[Rule('required|string|max:64')]
        public readonly string $target,
    ) {
    }
}

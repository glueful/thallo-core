<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Request body for `PUT /v1/admin/ui-settings/{roles|users}/{uuid}`
 * ({@see \Thallo\Core\Http\Controllers\AdminUiSettingsController::update()}): the whole of a role's
 * or user's settings, replacing what was there. `menus` maps a sidebar item's path to `hidden`, or
 * for a user also `shown`; `landing` is the admin path signing in lands on, null for Home.
 */
final class UpdateAdminUiSettingsData implements RequestData
{
    /** @param array<string,string> $menus */
    public function __construct(
        #[Rule('array')]
        public readonly array $menus = [],
        #[Rule('string')]
        public readonly ?string $landing = null,
    ) {
    }
}

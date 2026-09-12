<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs;

use Glueful\Validation\Attributes\FromQuery;
use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Query parameters for the API-key list ({@see \Thallo\Core\Http\Controllers\ApiKeyAdminController::index()}).
 * The router hydrates this from the query string; page/per_page clamping stays in the controller.
 */
final class ApiKeyListQuery implements RequestData
{
    public function __construct(
        #[FromQuery(description: 'Filter by status (active|expired|revoked).')]
        #[Rule('string|in:active,expired,revoked')]
        public readonly ?string $status = null,
        #[FromQuery(description: 'Case-insensitive substring filter on the key name.')]
        #[Rule('string')]
        public readonly ?string $q = null,
        // Query-string ints arrive as strings; `numeric` accepts "2" where `integer` would reject it.
        #[FromQuery(description: 'Page number (default 1).')]
        #[Rule('numeric')]
        public readonly ?int $page = null,
        #[FromQuery(description: 'Items per page (default 30, max 100).')]
        #[Rule('numeric')]
        public readonly ?int $per_page = null,
    ) {
    }
}

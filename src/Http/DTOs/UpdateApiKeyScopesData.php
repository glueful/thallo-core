<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Request body for `PATCH /v1/admin/api-keys/{uuid}/scopes`
 * ({@see \Thallo\Core\Http\Controllers\ApiKeyAdminController::updateScopes()}).
 *
 * Replaces the key's scopes wholesale with the given list (the collections admin composes
 * `{collection}.{read|write|delete}` strings and drives this endpoint).
 *
 * @param list<string> $scopes
 */
final class UpdateApiKeyScopesData implements RequestData
{
    public function __construct(
        #[Rule('array')]
        public readonly array $scopes = [],
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Request body for `POST /v1/admin/entries/{uuid}/preview/{locale}/apply`
 * ({@see \Thallo\Core\Content\Http\Controllers\EntryController::applyPreview()}).
 *
 * `token` is the preview session's HMAC token — the controller verifies it and
 * binds it to the route's entry+locale before anything else. `fields` stays a
 * bare `array` for the same reason as {@see SaveDraftData}: the per-field
 * semantic validation is the controller's FieldValidator.
 */
final class ApplyPreviewData implements RequestData
{
    public function __construct(
        #[Rule('required|string')]
        public readonly string $token = '',
        /** @var array<string,mixed> Working field values keyed by the content type's field names. */
        #[Rule('array')]
        public readonly array $fields = [],
    ) {
    }
}

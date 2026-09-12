<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Request body for `POST /v1/admin/entries`
 * ({@see \Thallo\Core\Content\Http\Controllers\EntryController::store()}).
 *
 * Hydrated by the router (v2): the flat `content_type`/`locale` scalars are validated here.
 * The content-type-exists check stays in the controller (unknown slug → 422).
 */
final class CreateEntryData implements RequestData
{
    public function __construct(
        /** @var string Slug of the content type to create an entry for. */
        #[Rule('required|string')]
        public readonly string $content_type,
        /** @var string|null BCP-47 locale for the seeded draft, e.g. "en". Defaults to the i18n default locale. */
        #[Rule('string')]
        public readonly ?string $locale = null,
    ) {
    }
}

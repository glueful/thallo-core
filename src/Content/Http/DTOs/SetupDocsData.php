<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Request body for `POST /v1/admin/docs/setup`
 * ({@see \Thallo\Core\Content\Http\Controllers\DocsSetupController::store()}). Both are optional:
 * an empty body makes the `docs` type with the default sections.
 */
final class SetupDocsData implements RequestData
{
    /** @param list<string>|null $sections */
    public function __construct(
        /** @var string|null The type's slug, which is the URL: `docs` serves /docs. Default `docs`. */
        #[Rule('string')]
        public readonly ?string $type = null,
        /** @var list<string>|null The sidebar's groups, in order (lower-case, hyphenated). */
        #[Rule('array')]
        public readonly ?array $sections = null,
    ) {
    }
}

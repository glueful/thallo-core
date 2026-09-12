<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Request body for `POST /v1/admin/entries/{uuid}/rollback/{locale}`
 * ({@see \Thallo\Core\Content\Http\Controllers\PublicationController::rollback()}).
 *
 * Hydrated by the router (v2): a missing/blank `version_uuid` fails here with a standard 422.
 * Whether the version belongs to this entry+locale is a domain rule that stays in the
 * controller/service (a {@see \RuntimeException} → 422).
 */
final class RollbackData implements RequestData
{
    public function __construct(
        /** @var string UUID of the version to re-publish. */
        #[Rule('required|string')]
        public readonly string $version_uuid,
    ) {
    }
}

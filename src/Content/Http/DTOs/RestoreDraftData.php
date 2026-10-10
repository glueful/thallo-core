<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/** Request body for `POST /v1/admin/entries/{uuid}/draft/{locale}/restore` (custom palette plan Task 12). */
final class RestoreDraftData implements RequestData
{
    public function __construct(
        /** The retained version to restore into the draft: one of this entry's, in this locale. */
        #[Rule('required|string')]
        public readonly string $version_uuid,
        /** The draft's optimistic-lock counter, as last read. */
        #[Rule('required|integer')]
        public readonly int $lock_version,
        /** The editor's palette ledger boundary. */
        #[Rule('nullable|integer')]
        public readonly ?int $palette_through = null,
    ) {
    }
}

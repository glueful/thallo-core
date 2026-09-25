<?php

declare(strict_types=1);

namespace Thallo\Core\Http\DTOs;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/** POST /admin/regions/preview/session body: the published page to show between the chrome. */
final class RegionSessionData implements RequestData
{
    public function __construct(
        /** Entry uuid of a published page; absent or unavailable means the homepage. */
        #[Rule('nullable|string')]
        public readonly ?string $page = null,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs\Requests;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * First-run web setup input (POST /admin/setup). Unauthenticated but self-locking — see
 * SetupController. Validation is intentionally strict: a real email and a non-trivial admin
 * password, since this seeds the only privileged account.
 */
final class SetupData implements RequestData
{
    public function __construct(
        #[Rule('required|string|max:200')]
        public readonly string $site_name,
        #[Rule('required|email')]
        public readonly string $admin_email,
        #[Rule('required|string|min:8')]
        public readonly string $admin_password,
        #[Rule('required|string|max:20')]
        public readonly string $locale = 'en',
        /** @var string|null The admin SPA's own origin — sent by the web setup form. */
        #[Rule('string|max:300')]
        public readonly ?string $admin_url = null,
    ) {
    }
}

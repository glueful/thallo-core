<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Palette\Http;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Request body for `POST /v1/admin/appearance/palette/brand/{slot}/replace` (custom palette spec
 * §4.2): the colour that takes the slot's place, and the one its text colour becomes — omitted, the
 * destination's own pair, or no mapping when nothing uses the slot's text colour.
 */
final class ReplaceBrandData implements RequestData
{
    public function __construct(
        /** @var string A colour token (`color.accent`, `color.brand-2`, …). */
        #[Rule('required|string')]
        public readonly string $to = '',
        /** @var string|null Where the slot's text colour goes. */
        #[Rule('string')]
        public readonly ?string $contrast_to = null,
    ) {
    }
}

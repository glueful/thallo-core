<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Palette\Http;

use Glueful\Validation\Attributes\Rule;
use Glueful\Validation\Contracts\RequestData;

/**
 * Request body for `POST /v1/admin/appearance/palette/preview` (custom palette spec §3): the
 * Appearance page's unsaved values, whose contrast it shows before anything is saved. Each field
 * absent reads the saved value.
 */
final class PalettePreviewData implements RequestData
{
    public function __construct(
        /** @var string|null An accent family, or a hex like #0a7c66. */
        #[Rule('string')]
        public readonly ?string $theme_accent = null,
        /** @var string|null A neutral family, or `custom`. */
        #[Rule('string')]
        public readonly ?string $theme_neutral = null,
        /** @var string|null The page ground (`plain`, `tinted`, …). */
        #[Rule('string')]
        public readonly ?string $theme_background = null,
        /**
         * @var array<string,mixed>|null `neutral_custom` (six hex colours), `dark_base` (a neutral
         *      family), `brands` (a list of {id, name, hex} in display order — the whole pending list;
         *      absent reads the saved one) — as a preview token's palette.
         */
        #[Rule('array')]
        public readonly ?array $palette = null,
    ) {
    }
}

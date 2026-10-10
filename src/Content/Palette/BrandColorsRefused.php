<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Palette;

/** A brand colour list the palette refuses (custom palette spec §2.3): a 422 on `theme_brand_colors`. */
final class BrandColorsRefused extends \DomainException
{
}

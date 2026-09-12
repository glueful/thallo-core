<?php

declare(strict_types=1);

namespace Thallo\Core\Settings;

use Thallo\Contracts\Settings\ThemeAppearanceProvider;

/**
 * Binds ThemeAppearanceProvider over GeneralSettings (theme-color-config spec §4).
 * Returns the effective saved-or-default family names; enum validation happens
 * downstream in ThemeAppearanceSource so an out-of-enum DB row falls back + logs
 * rather than throwing here.
 */
final class EngineThemeAppearanceProvider implements ThemeAppearanceProvider
{
    public function __construct(private readonly GeneralSettings $settings)
    {
    }

    public function accent(): string
    {
        return $this->settings->themeAccent();
    }

    public function neutral(): string
    {
        return $this->settings->themeNeutral();
    }
}

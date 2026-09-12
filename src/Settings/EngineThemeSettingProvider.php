<?php

declare(strict_types=1);

namespace Thallo\Core\Settings;

use Thallo\Contracts\Settings\ThemeSettingProvider;

/**
 * ThemeSettingProvider over the settings engine (theme-setting spec §2).
 * RAW-ROW contract: delegates to GeneralSettings::themeOverride() — never the
 * resolved effective value, so the render pack's ladder (override → env →
 * default) sees exactly what is stored and nothing more.
 */
final class EngineThemeSettingProvider implements ThemeSettingProvider
{
    public function __construct(private readonly GeneralSettings $settings)
    {
    }

    public function themeOverride(): ?string
    {
        return $this->settings->themeOverride();
    }
}

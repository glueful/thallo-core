<?php

declare(strict_types=1);

namespace Thallo\Core\Settings;

use Thallo\Contracts\Style\BrandSlot;
use Thallo\Contracts\Style\Palette;
use Thallo\Contracts\Style\PaletteProvider;
use Thallo\Core\Http\DTOs\UpdateGeneralSettingsData;
use Thallo\Render\Theme\ThemeColors;

/**
 * The palette from general settings (custom palette spec §2): six keys, read like the other theme
 * keys. A stored value that no longer parses (hand-edited, imported) reads as unset.
 */
final class PaletteSettings implements PaletteProvider
{
    public const NAME_MAX = 32;

    public function __construct(private readonly GeneralSettings $settings)
    {
    }

    public function palette(): Palette
    {
        $brands = [];
        foreach (Palette::SLOTS as $slot) {
            $brands[$slot] = self::parseBrand($this->settings->stored('theme_brand_' . $slot));
        }
        $base = $this->settings->stored('theme_dark_base');
        return new Palette(
            self::parseNeutral($this->settings->stored('theme_neutral_custom')),
            ThemeColors::normalizeNeutral($base) === null ? null : $base,
            $brands,
        );
    }

    /** `#abc` / `#aabbcc` in any case, as lower-case six digits; null for anything else. */
    public static function normalizeHex(string $value): ?string
    {
        $hex = ThemeColors::normalizeSiteAccent(trim($value));
        return $hex !== null && str_starts_with($hex, '#') ? $hex : null;
    }

    /** @return array<string,string>|null the six neutral values, or null unless all six parse */
    public static function parseNeutral(string $json): ?array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        $out = [];
        foreach (Palette::NEUTRAL_KEYS as $key) {
            $hex = is_string($data[$key] ?? null) ? self::normalizeHex($data[$key]) : null;
            if ($hex === null) {
                return null;
            }
            $out[$key] = $hex;
        }
        return $out;
    }

    public static function parseBrand(string $json): ?BrandSlot
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !is_string($data['name'] ?? null) || !is_string($data['hex'] ?? null)) {
            return null;
        }
        $name = trim($data['name']);
        $hex = self::normalizeHex($data['hex']);
        if ($name === '' || mb_strlen($name) > self::NAME_MAX || $hex === null) {
            return null;
        }
        return new BrandSlot($name, $hex);
    }

    /** @return array<string,string> field => message */
    public function validate(UpdateGeneralSettingsData $input): array
    {
        $errors = [];
        $sent = $input->theme_neutral_custom;
        if ($sent !== null && $sent !== '' && self::parseNeutral($sent) === null) {
            $errors['theme_neutral_custom'] = 'six hex colours are required: bg, surface, surface_2, ink, muted, line';
        }
        $neutral = $input->theme_neutral ?? $this->settings->themeNeutral();
        if ($neutral === 'custom') {
            $stored = self::parseNeutral($this->settings->stored('theme_neutral_custom'));
            if ($sent === '' || ($sent === null && $stored === null)) {
                $errors['theme_neutral_custom'] ??= 'Custom needs all six colours (reset only after choosing a family)';
            }
        }
        if ($input->theme_dark_base !== null && ThemeColors::normalizeNeutral($input->theme_dark_base) === null) {
            $errors['theme_dark_base'] = 'unknown neutral family';
        }
        foreach (Palette::SLOTS as $slot) {
            $key = 'theme_brand_' . $slot;
            $value = $input->{$key};
            if ($value === null) {
                continue;
            }
            if ($value === '') {
                $errors[$key] = 'clear a brand colour with Clear, which checks where it is used';
            } elseif (self::parseBrand($value) === null) {
                $errors[$key] = 'a name (1–' . self::NAME_MAX . ' characters) and a hex colour are required';
            }
        }
        return $errors;
    }

    /** The stored spelling of a submitted brand slot: trimmed name, normalised hex. */
    public static function encodeBrand(string $json): string
    {
        $slot = self::parseBrand($json) ?? throw new \InvalidArgumentException('invalid brand slot');
        return (string) json_encode($slot->toArray());
    }

    /** The stored spelling of the six neutral values; '' stays '' (Reset). */
    public static function encodeNeutral(string $json): string
    {
        if ($json === '') {
            return '';
        }
        $six = self::parseNeutral($json) ?? throw new \InvalidArgumentException('invalid neutral');
        return (string) json_encode($six);
    }
}

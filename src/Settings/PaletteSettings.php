<?php

declare(strict_types=1);

namespace Thallo\Core\Settings;

use Thallo\Contracts\Style\BrandSlot;
use Thallo\Contracts\Style\Palette;
use Thallo\Contracts\Style\PaletteProvider;
use Thallo\Core\Http\DTOs\UpdateGeneralSettingsData;
use Thallo\Render\Theme\ThemeColors;

/**
 * The palette from general settings (custom palette spec §2): the neutral keys and the brand colour
 * list (`theme_brand_colors`), read like the other theme keys, under the deployment's limit. A stored
 * value that no longer parses (hand-edited, imported) reads as unset.
 */
final class PaletteSettings implements PaletteProvider
{
    public const NAME_MAX = 32;

    public function __construct(
        private readonly GeneralSettings $settings,
        private readonly int $brandLimit = Palette::DEFAULT_LIMIT,
    ) {
    }

    /** The deployment's limit (custom palette spec §1): 0–12, the default for anything not a number. */
    public static function limitFrom(mixed $raw): int
    {
        if (!is_int($raw) && !(is_string($raw) && is_numeric(trim($raw)))) {
            return Palette::DEFAULT_LIMIT;
        }
        return max(0, min(Palette::LIMIT_CEILING, (int) $raw));
    }

    public function palette(): Palette
    {
        [$colors, $removed] = BrandColors::parse($this->settings->stored('theme_brand_colors'));
        $base = $this->settings->stored('theme_dark_base');
        return new Palette(
            self::parseNeutral($this->settings->stored('theme_neutral_custom')),
            ThemeColors::normalizeNeutral($base) === null ? null : $base,
            $colors,
            $removed,
            $this->brandLimit,
        );
    }

    public function preview(array $claim): Palette
    {
        $saved = $this->palette();
        $sent = $claim['neutral_custom'] ?? null;
        $neutral = array_key_exists('neutral_custom', $claim)
            ? (is_array($sent) ? self::parseNeutral((string) json_encode($sent)) : null)
            : $saved->customNeutral;
        $base = array_key_exists('dark_base', $claim)
            ? (is_string($claim['dark_base']) ? ThemeColors::normalizeNeutral($claim['dark_base']) : null)
            : $saved->darkBase;
        $brands = $saved->brands;
        foreach (is_array($claim['brands'] ?? null) ? $claim['brands'] : [] as $slot => $brand) {
            $slot = (int) $slot;
            if (BrandColors::id($slot) !== null) {
                $brands[$slot] = is_array($brand) ? self::parseBrand((string) json_encode($brand)) : null;
            }
        }
        return new Palette($neutral, $base, $brands, $saved->removed, $saved->limit);
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

    /**
     * A pending palette (custom palette spec §5.1) as a preview claim, normalised (hex lower-case,
     * names trimmed), or null when any part is invalid. Only the keys sent are carried; the render
     * falls back to the saved values for the rest.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>|null
     */
    public static function previewClaim(array $input): ?array
    {
        $out = [];
        foreach ($input as $key => $value) {
            switch ($key) {
                case 'neutral_custom':
                    if ($value === null) {
                        $out[$key] = null;
                        break;
                    }
                    $six = is_array($value) ? self::parseNeutral((string) json_encode($value)) : null;
                    if ($six === null) {
                        return null;
                    }
                    $out[$key] = $six;
                    break;
                case 'dark_base':
                    if ($value !== null && (!is_string($value) || ThemeColors::normalizeNeutral($value) === null)) {
                        return null;
                    }
                    $out[$key] = $value;
                    break;
                case 'brands':
                    if (!is_array($value)) {
                        return null;
                    }
                    $brands = [];
                    foreach ($value as $slot => $brand) {
                        if (!in_array((string) $slot, ['1', '2', '3'], true)) {
                            return null;
                        }
                        if ($brand === null) {
                            $brands[(int) $slot] = null;
                            continue;
                        }
                        $parsed = is_array($brand) ? self::parseBrand((string) json_encode($brand)) : null;
                        if ($parsed === null) {
                            return null;
                        }
                        $brands[(int) $slot] = $parsed->toArray();
                    }
                    $out[$key] = $brands;
                    break;
                default:
                    return null;
            }
        }
        return $out;
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
        // the revision-4 keys: removed with the list's save (next task)
        foreach ([1, 2, 3] as $slot) {
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

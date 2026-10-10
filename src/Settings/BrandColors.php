<?php

declare(strict_types=1);

namespace Thallo\Core\Settings;

use Thallo\Contracts\Style\BrandSlot;
use Thallo\Contracts\Style\Palette;

/**
 * The stored brand colour list (custom palette spec §2, §2.3): `theme_brand_colors`, as
 * `{"colors":[{id,name,hex}…],"removed":[{id,name}…]}` — colours in the author's order, removed ids
 * with the names they had. A stored entry that no longer parses (hand-edited, imported) reads as
 * unset; the rest stay.
 */
final class BrandColors
{
    /** @return array{0: array<int,BrandSlot>, 1: array<int,string>, 2: int} colours, removed names, revision */
    public static function parse(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return [[], [], 0];
        }
        $colors = [];
        foreach (is_array($data['colors'] ?? null) ? $data['colors'] : [] as $row) {
            $id = is_array($row) ? self::id($row['id'] ?? null) : null;
            $slot = $id === null ? null : PaletteSettings::parseBrand((string) json_encode($row));
            if ($slot !== null && !isset($colors[$id])) {
                $colors[$id] = $slot;
            }
        }
        $removed = [];
        foreach (is_array($data['removed'] ?? null) ? $data['removed'] : [] as $row) {
            $id = is_array($row) ? self::id($row['id'] ?? null) : null;
            $name = is_array($row) && is_string($row['name'] ?? null) ? trim($row['name']) : '';
            if ($id !== null && $name !== '' && !isset($colors[$id])) {
                $removed[$id] = mb_substr($name, 0, PaletteSettings::NAME_MAX);
            }
        }
        $revision = is_int($data['revision'] ?? null) && $data['revision'] >= 0 ? $data['revision'] : 0;
        return [$colors, $removed, $revision];
    }

    /**
     * @param array<int,BrandSlot> $colors
     * @param array<int,string> $removed
     * @param int $revision what a submitted list must name as its base to be saved over this one
     */
    public static function encode(array $colors, array $removed, int $revision): string
    {
        $out = ['revision' => $revision, 'colors' => [], 'removed' => []];
        foreach ($colors as $id => $slot) {
            $out['colors'][] = ['id' => $id] + $slot->toArray();
        }
        ksort($removed);
        foreach ($removed as $id => $name) {
            $out['removed'][] = ['id' => $id, 'name' => $name];
        }
        return (string) json_encode($out);
    }

    /**
     * The stored list with `$id` moved from the colours to the removed, keeping its name (§2.3), at
     * the next revision — so an editor holding the list from before is refused, not obeyed. Unchanged
     * when `$id` is not a colour.
     */
    public static function cleared(string $stored, int $id): string
    {
        [$colors, $removed, $revision] = self::parse($stored);
        if (!isset($colors[$id])) {
            return $stored;
        }
        $removed[$id] = $colors[$id]->name;
        unset($colors[$id]);
        return self::encode($colors, $removed, $revision + 1);
    }

    /** An id from 1 to Palette::MAX_ID, else null. */
    public static function id(mixed $value): ?int
    {
        return is_int($value) && $value >= 1 && $value <= Palette::MAX_ID ? $value : null;
    }
}

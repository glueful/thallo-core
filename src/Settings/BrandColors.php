<?php

declare(strict_types=1);

namespace Thallo\Core\Settings;

use Thallo\Contracts\Style\BrandSlot;
use Thallo\Contracts\Style\Palette;
use Thallo\Core\Content\Palette\BrandColorsRefused;
use Thallo\Core\Content\Palette\PaletteConflict;

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

    /**
     * A submitted list (custom palette spec §2.3) applied to the palette held under its row, as the
     * value to store. New colours (no id) take the ids above the highest the workspace has ever
     * issued, in the order submitted; configured colours may be renamed, re-coloured and reordered;
     * a colour may leave only through Clear, so a list that omits one is refused; an id that is not
     * configured is refused by its name; a save that adds a colour must stay within the limit,
     * while colours above a lowered limit stay editable. A colour being replaced may move but not
     * change. A list edited from an older revision is refused whatever it changes, so a save never
     * reverts what someone else saved meanwhile (plan ruling 3).
     *
     * @param int $revision the stored list's revision, read under the palette row
     * @param int $base the revision the submitted list was edited from
     * @param list<array{id: ?int, name: string, hex: string}> $rows
     * @param \Closure(int): bool $replacing whether a running replacement replaces the id
     * @return string the value to store, at `$revision + 1`
     * @throws BrandColorsRefused
     * @throws PaletteConflict
     */
    public static function applied(Palette $held, int $revision, int $base, array $rows, \Closure $replacing): string
    {
        if ($base !== $revision) {
            throw new PaletteConflict('Brand colours changed since you opened this page — reload to see the latest');
        }
        if ($held->limit === 0) {
            throw new BrandColorsRefused('Brand colours are turned off on this site');
        }
        $sent = array_values(array_filter(array_column($rows, 'id'), 'is_int'));
        foreach ($held->brands as $id => $brand) {
            if (!in_array($id, $sent, true)) {
                throw new BrandColorsRefused(
                    "Remove a brand colour with Clear: {$brand->name} is missing from this save",
                );
            }
        }
        foreach ($sent as $id) {
            if (!isset($held->brands[$id])) {
                throw new BrandColorsRefused("{$held->labelOf($id)} isn't in the palette");
            }
        }
        if (count($rows) > count($sent) && count($rows) > $held->limit) {
            $unit = $held->limit === 1 ? 'brand colour' : 'brand colours';
            throw new BrandColorsRefused("This site allows {$held->limit} {$unit}");
        }
        $next = $held->highestIssued();
        $colors = [];
        foreach ($rows as $row) {
            $slot = new BrandSlot($row['name'], $row['hex']);
            if ($row['id'] === null) {
                if (++$next > Palette::MAX_ID) {
                    throw new BrandColorsRefused('No brand colour ids are left on this site');
                }
                $colors[$next] = $slot;
                continue;
            }
            $current = $held->brands[$row['id']];
            if ($current->toArray() !== $slot->toArray() && $replacing($row['id'])) {
                throw new PaletteConflict("{$current->name} is being replaced");
            }
            $colors[$row['id']] = $slot;
        }
        return self::encode($colors, $held->removed, $revision + 1);
    }

    /** An id from 1 to Palette::MAX_ID, else null. */
    public static function id(mixed $value): ?int
    {
        return is_int($value) && $value >= 1 && $value <= Palette::MAX_ID ? $value : null;
    }
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Blocks;

use Thallo\Contracts\Style\StyleTargets;

/**
 * The style declaration of a block type MADE IN THE ADMIN. A code-declared type says in code which
 * settings land on which of its elements; an admin cannot be asked to. So a custom block has ONE
 * style target — `root`, a box: its outermost element — and the admin chooses which SETTING GROUPS
 * it supports, from the groups a box can carry. The template emits them with
 * `{{ style_classes('root') }}` and `{{ style_attrs('root') }}` on that element.
 *
 * Not offered: text alignment (it needs a TEXT target), the parent layout groups (a STACK: what
 * arranges a container's children), and the groups that belong to one block's own parts (a
 * feature's marker, a tab strip).
 */
final class CustomBlockStyle
{
    /** @var list<string> setting groups a custom block may declare, in the Style tab's order */
    public const GROUPS = [
        'spacing', 'width', 'alignment.self', 'typography', 'colors', 'backdrop', 'radius', 'border',
        'shadow', 'visibility', 'layout.min_height', 'layout.overflow', 'layout.item', 'motion',
    ];

    /**
     * @param list<mixed> $capabilities
     * @return string|null what is wrong with the choice, or null
     */
    public static function problem(array $capabilities): ?string
    {
        foreach ($capabilities as $capability) {
            if (!is_string($capability) || !in_array($capability, self::GROUPS, true)) {
                $name = is_string($capability) ? $capability : gettype($capability);
                return "'{$name}' is not a setting group a custom block can have; choose from: "
                    . implode(', ', self::GROUPS);
            }
        }
        return count($capabilities) === count(array_unique($capabilities)) ? null : 'a group is listed twice';
    }

    /**
     * The one-target declaration for the chosen groups.
     *
     * @param list<string> $capabilities
     * @return array{targets: array<string, array<string,mixed>>, map: array<string,string>}
     */
    public static function targets(array $capabilities): array
    {
        return StyleTargets::root('box', $capabilities);
    }
}

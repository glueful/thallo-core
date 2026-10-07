<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Blocks;

use Thallo\Contracts\Style\StyleCapabilities;
use Thallo\Contracts\Style\StyleSchema;
use Thallo\Contracts\Style\StyleTargets;

/**
 * What a block type offers, expanded once (hover state spec §2.2.1): the block's effective style
 * paths — every target's, after the target-aware hover rule — and each part's, in schema order. The
 * admin reads this instead of expanding groups itself. Derived on read, never stored, so a custom
 * block type has it too.
 */
final class BlockTypeStylePaths
{
    /**
     * @param array<string,mixed> $row a block type row (style_capabilities and style_targets decoded)
     * @return array{block: list<string>, parts: array<string, list<string>>}
     */
    public static function for(array $row): array
    {
        $declared = is_array($row['style_capabilities'] ?? null) ? array_values($row['style_capabilities']) : null;
        $caps = StyleCapabilities::fromDeclaration($declared);
        $declaredTargets = $row['style_targets'] ?? null;
        $targets = is_array($declaredTargets) ? StyleTargets::fromDeclaration($declaredTargets) : null;
        // The one published form (StyleTargets::stylePaths): effective and in schema order. A type with
        // no targets declaration has one implicit target and no parts.
        return $targets?->stylePaths($caps) ?? ['block' => StyleSchema::ordered($caps->paths()), 'parts' => []];
    }

    /**
     * The row as the admin reads it: with its `style_paths`.
     *
     * @param array<string,mixed>|null $row
     * @return array<string,mixed>|null
     */
    public static function attach(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        try {
            $paths = self::for($row);
        } catch (\InvalidArgumentException) {
            // A stored declaration the current contract no longer parses (written around the
            // repository's checks, or by an older release): the type offers nothing, and the list
            // still loads for every other type.
            $paths = ['block' => [], 'parts' => []];
        }
        // `parts` is a map: no parts is `{}` in JSON, never `[]`.
        return $row + ['style_paths' => ['block' => $paths['block'], 'parts' => $paths['parts'] ?: new \stdClass()]];
    }
}

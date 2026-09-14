<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Style;

use Thallo\Contracts\Style\BlockStyleRegistry;
use Thallo\Contracts\Style\StyleCapabilities;
use Thallo\Contracts\Style\StyleTargets;
use Thallo\Core\Content\Blocks\BlockTypeRepository;

/**
 * The application's BlockStyleRegistry (visual builder spec §1.7): reads the style declaration
 * keys off block type rows, memoised per request. Rows are validated on write, so parsing here
 * cannot fail for stored data; an unknown type means none.
 */
final class EngineBlockStyleRegistry implements BlockStyleRegistry
{
    /** @var array<string, array<string,mixed>>|null slug => row */
    private ?array $rows = null;

    public function __construct(private readonly BlockTypeRepository $blockTypes)
    {
    }

    public function capabilitiesFor(string $type): StyleCapabilities
    {
        $declared = $this->row($type)['style_capabilities'] ?? null;
        return StyleCapabilities::fromDeclaration(is_array($declared) ? $declared : null);
    }

    public function targetsFor(string $type): ?StyleTargets
    {
        $declared = $this->row($type)['style_targets'] ?? null;
        return is_array($declared) ? StyleTargets::fromDeclaration($declared) : null;
    }

    public function flagsFor(string $type): array
    {
        $flags = $this->row($type)['flags'] ?? null;
        return is_array($flags) ? $flags : [];
    }

    public function regionsFor(string $type): array
    {
        $names = [];
        foreach ((array) ($this->row($type)['schema'] ?? []) as $field) {
            if (is_array($field) && ($field['type'] ?? null) === 'blocks' && is_string($field['name'] ?? null)) {
                $names[] = $field['name'];
            }
        }
        return $names;
    }

    /** Drop the memo (tests and long-running processes that change block types). */
    public function reset(): void
    {
        $this->rows = null;
    }

    /** @return array<string,mixed>|null */
    private function row(string $type): ?array
    {
        if ($this->rows === null) {
            $this->rows = [];
            foreach ($this->blockTypes->all() as $row) {
                $this->rows[(string) $row['slug']] = $row;
            }
        }
        return $this->rows[$type] ?? null;
    }
}

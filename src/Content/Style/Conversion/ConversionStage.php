<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Style\Conversion;

/**
 * A named conversion stage (visual builder plan A4.5): its rows. A document records the stages
 * it completed in `_schema.conversions`; a later stage still runs on a document converted by an
 * earlier one.
 */
final class ConversionStage
{
    /** @var array<string, array<string, ConversionRule>> block type => field => rule */
    private array $rules = [];

    /** @param list<ConversionRule> $rules */
    public function __construct(public readonly string $name, array $rules)
    {
        foreach ($rules as $rule) {
            $this->rules[$rule->blockType][$rule->field] = $rule;
        }
    }

    /** @return array<string, ConversionRule> field => rule */
    public function rulesFor(string $blockType): array
    {
        return $this->rules[$blockType] ?? [];
    }

    public function touches(string $blockType): bool
    {
        return isset($this->rules[$blockType]);
    }
}

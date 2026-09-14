<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Style\Conversion;

/** The ordered stages, and which of them a document still has to run (`_schema.conversions`). */
final class ConversionStages
{
    /** @var list<ConversionStage> */
    private readonly array $stages;

    public function __construct(ConversionStage ...$stages)
    {
        $this->stages = array_values($stages);
    }

    /** @return list<ConversionStage> */
    public function all(): array
    {
        return $this->stages;
    }

    /**
     * @param array<string,mixed> $fields a document's fields (with or without a `_schema` stamp)
     * @return list<ConversionStage> in order
     */
    public function pending(array $fields): array
    {
        $done = self::completed($fields);
        return array_values(array_filter(
            $this->stages,
            static fn (ConversionStage $s): bool => !in_array($s->name, $done, true),
        ));
    }

    /**
     * @param array<string,mixed> $fields
     * @return list<string>
     */
    public static function completed(array $fields): array
    {
        $stamp = $fields['_schema'] ?? null;
        $done = is_array($stamp) && is_array($stamp['conversions'] ?? null) ? $stamp['conversions'] : [];
        return array_values(array_filter($done, 'is_string'));
    }
}

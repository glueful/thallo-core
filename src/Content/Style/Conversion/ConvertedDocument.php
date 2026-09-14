<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Style\Conversion;

/** One document after conversion: its fields (stamped), whether anything changed, and what is unresolved. */
final readonly class ConvertedDocument
{
    /**
     * @param array<string,mixed> $fields
     * @param list<string> $stages the stages this conversion completed
     */
    public function __construct(
        public array $fields,
        public bool $changed,
        public int $unresolved,
        public array $stages,
    ) {
    }
}

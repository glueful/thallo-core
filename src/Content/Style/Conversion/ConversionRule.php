<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Style\Conversion;

/**
 * One row of the conversion table (visual builder spec §7.2): what happens to a legacy field of
 * a block type — converted by an explicit translation, kept as block semantics, or unmappable
 * (every raw hex and pixel value), which requires an author-selected token or an explicit
 * discard recorded as a decision. A decision's token lands where the row says: a settings path
 * or a data field (as a typed token value).
 */
final readonly class ConversionRule
{
    public const CONVERT = 'convert';
    public const KEEP = 'keep';
    public const UNMAPPABLE = 'unmappable';

    /**
     * @param (\Closure(mixed $value, array<string,mixed> $data): ConversionOutcome)|null $fn
     * @param string|null $decisionTarget `setting:<path>` or `data:<field>` — where a decided token goes
     */
    private function __construct(
        public string $blockType,
        public string $field,
        public string $kind,
        public ?\Closure $fn,
        public ?string $reason,
        public ?string $tokenDomain,
        public ?string $decisionTarget,
    ) {
    }

    /** @param \Closure(mixed $value, array<string,mixed> $data): ConversionOutcome $fn */
    public static function convert(string $blockType, string $field, \Closure $fn): self
    {
        return new self($blockType, $field, self::CONVERT, $fn, null, null, null);
    }

    public static function keep(string $blockType, string $field): self
    {
        return new self($blockType, $field, self::KEEP, null, null, null, null);
    }

    /**
     * @param string|null $tokenDomain the vocabulary domain a decision may pick from
     * @param string|null $decisionTarget `setting:<path>` or `data:<field>`
     */
    public static function unmappable(
        string $blockType,
        string $field,
        string $reason,
        ?string $tokenDomain = null,
        ?string $decisionTarget = null,
    ): self {
        return new self($blockType, $field, self::UNMAPPABLE, null, $reason, $tokenDomain, $decisionTarget);
    }

    /** @param array<string,mixed> $data */
    public function apply(mixed $value, array $data): ConversionOutcome
    {
        return match ($this->kind) {
            self::CONVERT => ($this->fn)($value, $data),
            self::KEEP => ConversionOutcome::kept(),
            default => ConversionOutcome::unmappable((string) $this->reason),
        };
    }
}

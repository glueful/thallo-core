<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Style\Conversion;

/** What a conversion rule decided for one legacy field value (visual builder spec §7.2). */
final readonly class ConversionOutcome
{
    public const SETTING = 'setting';
    public const ADVANCED = 'advanced';
    public const DATA = 'data';
    public const DISCARD = 'discard';
    public const UNMAPPABLE = 'unmappable';
    public const KEPT = 'kept';

    private function __construct(
        public string $kind,
        public ?string $target = null,
        public mixed $value = null,
        public string $breakpoint = 'base',
        public ?string $reason = null,
    ) {
    }

    /** The value becomes a typed settings value at `$path` (`base` unless stated). */
    public static function setting(string $path, array $value, string $breakpoint = 'base'): self
    {
        return new self(self::SETTING, $path, $value, $breakpoint);
    }

    /** The value becomes an advanced setting (`anchor`, `css_classes`, `attributes`, `accessibility.label`). */
    public static function advanced(string $path, mixed $value): self
    {
        return new self(self::ADVANCED, $path, $value);
    }

    /** The value becomes (or rewrites) a data field: block semantics that stay in `data`. */
    public static function data(string $field, mixed $value): self
    {
        return new self(self::DATA, $field, $value);
    }

    public static function discard(): self
    {
        return new self(self::DISCARD);
    }

    /** Needs an author's decision: a token, a documented transformation, or an explicit discard. */
    public static function unmappable(string $reason): self
    {
        return new self(self::UNMAPPABLE, null, null, 'base', $reason);
    }

    public static function kept(): self
    {
        return new self(self::KEPT);
    }
}

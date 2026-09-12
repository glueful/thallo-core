<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Retention;

/**
 * Validated version-retention policy. A null dimension is disabled; any configured
 * dimension must be a positive integer so pruning cannot silently become destructive
 * through loose casts such as (int) "" === 0.
 */
final class RetentionPolicy
{
    public function __construct(
        public readonly ?int $keep,
        public readonly ?int $maxAgeDays,
    ) {
        if ($keep !== null && $keep < 1) {
            throw InvalidRetentionPolicyException::forField('keep', $keep);
        }
        if ($maxAgeDays !== null && $maxAgeDays < 1) {
            throw InvalidRetentionPolicyException::forField('max_age_days', $maxAgeDays);
        }
    }

    public static function fromValues(mixed $keep, mixed $maxAgeDays): self
    {
        return new self(
            self::parseDimension('keep', $keep),
            self::parseDimension('max_age_days', $maxAgeDays),
        );
    }

    public function isEnabled(): bool
    {
        return $this->keep !== null || $this->maxAgeDays !== null;
    }

    private static function parseDimension(string $field, mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value >= 1 ? $value : throw InvalidRetentionPolicyException::forField($field, $value);
        }

        if (is_string($value) && preg_match('/^[1-9][0-9]*$/', $value) === 1) {
            return (int) $value;
        }

        throw InvalidRetentionPolicyException::forField($field, $value);
    }
}

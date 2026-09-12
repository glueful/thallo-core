<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Retention;

final class InvalidRetentionPolicyException extends \InvalidArgumentException
{
    public static function forField(string $field, mixed $value): self
    {
        return new self(sprintf(
            '%s must be empty/null or a positive integer (>= 1); got %s.',
            $field,
            var_export($value, true),
        ));
    }
}

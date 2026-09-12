<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Delivery;

/**
 * Thrown for a malformed filter: an operator not allowed for the field's filter_type,
 * an unknown operator, or a value that cannot be coerced to the field's type.
 */
final class InvalidFilterException extends \InvalidArgumentException
{
}

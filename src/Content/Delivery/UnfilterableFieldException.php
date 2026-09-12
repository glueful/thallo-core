<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Delivery;

/**
 * Thrown when a request tries to filter on a field that is unknown or not declared
 * `filterable` in the content type schema (a product rule, V1_DESIGN §1).
 */
final class UnfilterableFieldException extends \InvalidArgumentException
{
}

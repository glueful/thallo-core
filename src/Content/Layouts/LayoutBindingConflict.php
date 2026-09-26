<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Layouts;

/**
 * A content-type change would delete a field a layout shows (type layouts spec §5.7): refused, with
 * the layouts that use it named, so a binding never breaks silently.
 */
final class LayoutBindingConflict extends \RuntimeException
{
    /** @param array<string, list<string>> $bound field => the labels of the layouts that show it */
    public function __construct(public readonly array $bound)
    {
        $parts = [];
        foreach ($bound as $field => $labels) {
            $parts[] = "'{$field}' is shown by " . implode(', ', $labels);
        }
        parent::__construct(implode('; ', $parts) . ' — change those layouts first');
    }
}

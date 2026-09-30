<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Patterns;

/**
 * Core's sections and templates for the layout editor (sections and templates design §3, §6): the
 * single post, listing and archive layouts, each built for the layout's target.
 */
final class LayoutPatterns
{
    /** Every layout surface a pattern may name; the product and shop ones exist while Commerce is on. */
    public const SURFACES = ['entry', 'listing', 'archive', 'product', 'shop_index', 'shop_category'];

    /** @return array<string,string> every core layout pattern's slug => its surface */
    public static function slugs(): array
    {
        return [];
    }
}

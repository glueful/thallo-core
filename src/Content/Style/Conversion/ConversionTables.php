<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Style\Conversion;

/**
 * The shipped conversion table (visual builder spec §7.2): one stage per release that retires a
 * block type's presentation fields, each row an explicit translation, a keep (block semantics
 * that stay in data) or unmappable with the domain a decision may pick from. No stage ships
 * today — every install is authored in the settings shape — so the converter, the decisions
 * file and the provision preflight stand ready for the first retirement.
 */
final class ConversionTables
{
    public static function shipped(): ConversionStages
    {
        return new ConversionStages();
    }
}

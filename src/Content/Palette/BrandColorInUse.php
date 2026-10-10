<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Palette;

/**
 * A brand colour that cannot be cleared (custom palette spec §4): drafts, current publications,
 * regions, layouts, saved sections or style classes still name it. Carries where, as
 * BrandColorUsage::of() reports it, so the dialog can list them and offer Replace with….
 */
final class BrandColorInUse extends \RuntimeException
{
    /** @param array<string,mixed> $usage */
    public function __construct(public readonly array $usage)
    {
        parent::__construct('the brand colour is still in use');
    }
}

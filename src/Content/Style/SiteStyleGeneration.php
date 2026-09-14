<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Style;

use Thallo\Tenancy\System\SystemFlags;

/**
 * The site style generation (visual builder spec §3.5, §4.3): a system flag every apply
 * response names, so the editor re-resolves inherited values before trusting them whenever a
 * style class changed underneath it. Incremented by the class mutations that land in Phase B;
 * Phase A only reads and reports it.
 */
final class SiteStyleGeneration
{
    public const FLAG = 'style.generation';

    public function __construct(private readonly SystemFlags $flags)
    {
    }

    public function current(): int
    {
        return (int) ($this->flags->get(self::FLAG) ?? 0);
    }

    public function increment(): int
    {
        $next = $this->current() + 1;
        $this->flags->put(self::FLAG, (string) $next);
        return $next;
    }
}

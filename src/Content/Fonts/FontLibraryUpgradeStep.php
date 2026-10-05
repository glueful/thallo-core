<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Fonts;

use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Provision's font library step (block typeface spec §2.7): the one-time Custom upgrade in every
 * workspace — each in its own context, with its own marker — or once on a single site.
 */
final class FontLibraryUpgradeStep
{
    public function __construct(
        private readonly FontLibraryUpgrade $upgrade,
        private readonly SystemFlags $flags,
        private readonly ?TenantContextRunner $runner = null,
    ) {
    }

    /** @return array{workspaces: int, created: int} */
    public function run(): array
    {
        if (!$this->flags->tenancyEnabled() || $this->runner === null) {
            return ['workspaces' => 1, 'created' => $this->upgrade->run()['created']];
        }
        $totals = ['workspaces' => 0, 'created' => 0];
        $this->runner->forEachTenant(function () use (&$totals): void {
            $totals['workspaces']++;
            $totals['created'] += $this->upgrade->run()['created'];
        });
        return $totals;
    }
}

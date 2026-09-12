<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Blocks;

use Thallo\Core\Content\Starter\Kinds\BlockTypeKind;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Seeds a pack's starter block types on the first request after its capability turns on, so
 * enabling Commerce (or Accounts) makes its blocks appear without `thallo:provision` or
 * `thallo:blocks:seed`. Runs once per request, after every provider has booted (packs declare
 * their contributions in their own boot); costs one cached flag read unless something changed.
 *
 * A system flag lists the capabilities whose contributions were seeded. A capability that is on
 * and not listed gets its missing definitions created (existing rows are never touched) and is
 * added; a capability that is off and listed is removed — its rows stay, hidden from the
 * listing by {@see BlockTypeKind::hiddenSlugs()} — so switching it on again seeds whatever is
 * missing by then. Single-store only: with workspaces on, each tenant seeds through
 * `thallo:blocks:seed --tenant` / `thallo:tenant:sync --kind=block_type`.
 */
final class ContributedBlockTypeReconciler
{
    public const FLAG = 'starter_block_types.seeded_capabilities';

    public function __construct(
        private readonly BlockTypeKind $kind,
        private readonly StarterBlockTypeSeeder $seeder,
        private readonly CapabilityRegistry $capabilities,
        private readonly SystemFlags $flags,
    ) {
    }

    /** @return array<string, list<string>> slugs created on this run, per capability seeded */
    public function reconcile(): array
    {
        // Before install the fresh-install seed (SetupService) owns the whole library.
        if ($this->flags->get('installed') !== '1' || $this->flags->tenancyEnabled()) {
            return [];
        }

        $seeded = $this->seededCapabilities();
        $report = [];
        $changed = false;
        foreach ($this->kind->gatedCapabilities() as $capability) {
            $on = $this->capabilities->isEnabled($capability);
            $listed = in_array($capability, $seeded, true);
            if ($on && !$listed) {
                $report[$capability] = $this->seeder
                    ->seedMissingAmong($this->kind->contributionsFor($capability))['created'];
                $seeded[] = $capability;
                $changed = true;
            } elseif (!$on && $listed) {
                $seeded = array_values(array_diff($seeded, [$capability]));
                $changed = true;
            }
        }
        if ($changed) {
            $this->flags->put(self::FLAG, (string) json_encode($seeded));
        }

        return $report;
    }

    /** @return list<string> */
    private function seededCapabilities(): array
    {
        $raw = $this->flags->get(self::FLAG);
        $decoded = $raw === null ? [] : json_decode($raw, true);
        if (!is_array($decoded)) {
            return [];
        }
        return array_values(array_filter($decoded, 'is_string'));
    }
}

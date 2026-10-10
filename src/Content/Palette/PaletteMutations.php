<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Palette;

use Glueful\Database\Connection;
use Glueful\Events\EventService;
use Glueful\Extensions\Audit\Contracts\AuditRecorderInterface;
use Glueful\Extensions\Audit\Support\AuditEntry;
use Thallo\Contracts\Settings\ThemeAppearanceChanged;
use Thallo\Contracts\Style\Palette;
use Thallo\Core\Settings\BrandColors;
use Thallo\Core\Settings\GeneralSettings;
use Thallo\Core\Settings\PaletteSettings;

/**
 * Every change to the palette (custom palette spec §4, §4.3): under the palette row, checked against
 * running replacements and their reservations, the generation bumped when the palette changed,
 * effects only after commit.
 */
final class PaletteMutations
{
    public function __construct(
        private readonly Connection $db,
        private readonly PaletteFence $fence,
        private readonly PaletteState $state,
        private readonly GeneralSettings $settings,
        private readonly BrandColorUsage $usage,
        private readonly ?EventService $events = null,
        private readonly ?AuditRecorderInterface $audit = null,
    ) {
    }

    /**
     * Save palette keys already validated and in their stored spelling (`theme_brand_N`,
     * `theme_neutral_custom`, `theme_dark_base`). A slot a running replacement replaces cannot be
     * renamed or re-coloured; a reserved one can; the neutrals are never blocked.
     *
     * @param array<string,string> $pairs
     * @return bool whether the palette changed
     * @throws PaletteConflict
     */
    public function save(array $pairs, ?string $actor): bool
    {
        if ($pairs === []) {
            return false;
        }
        return $this->fence->within(function () use ($pairs): bool {
            $held = $this->state->lock();
            // the revision-4 keys: removed with the list's save (next task)
            foreach ([1, 2, 3] as $slot) {
                $key = 'theme_brand_' . $slot;
                if (!array_key_exists($key, $pairs)) {
                    continue;
                }
                $current = $held->palette->brand($slot);
                $next = PaletteSettings::parseBrand($pairs[$key]);
                if ($current?->toArray() !== $next?->toArray() && $held->jobReplacing($slot) !== null) {
                    throw new PaletteConflict(($current?->name ?? "Brand {$slot}") . ' is being replaced');
                }
            }
            $this->settings->save($pairs);
            $changed = $this->state->snapshot()->palette->fingerprint() !== $held->palette->fingerprint();
            if ($changed) {
                $this->state->bump();
            }
            return $changed;
        });
    }

    /**
     * Clear a brand colour (custom palette spec §4): refused while a running replacement replaces or
     * writes to the slot, and while any draft, current publication, region, layout, saved section or
     * class names it — scanned inside the transaction, after the bump, so a save that committed first
     * is seen and one racing after is refused by the fence. Historical versions never block.
     *
     * @throws BrandColorInUse
     * @throws PaletteConflict
     */
    public function clear(int $slot, ?string $actor): void
    {
        $name = null;
        $this->fence->within(function () use ($slot, &$name): void {
            $held = $this->state->lock();
            $brand = $held->palette->brand($slot);
            if ($brand === null) {
                return; // already clear
            }
            if ($held->jobReplacing($slot) !== null || in_array($slot, $held->reservedSlots(), true)) {
                throw new PaletteConflict("{$brand->name} is part of a running replacement");
            }
            $this->state->bump();
            $usage = $this->usage->of($slot);
            if (($usage['blocking']['total'] ?? 0) > 0) {
                throw new BrandColorInUse($usage); // the transaction, and the bump, roll back
            }
            // Moved to removed, keeping its name (spec §2.3); lock() cleared the store's read cache.
            $this->settings->save([
                'theme_brand_colors' => BrandColors::cleared($this->settings->stored('theme_brand_colors'), $slot),
            ]);
            $name = $brand->name;
        });
        if ($name === null) {
            return;
        }
        $this->db->afterCommit(function () use ($slot, $name, $actor): void {
            $this->audit?->record(new AuditEntry(
                occurredAt: microtime(true),
                action: 'palette.brand.cleared',
                category: 'content',
                actorUuid: $actor,
                targetType: 'palette_slot',
                targetUuid: 'brand-' . $slot,
                targetLabel: $name,
            ));
            $this->events?->dispatch(new ThemeAppearanceChanged(
                $this->settings->themeAccent(),
                $this->settings->themeNeutral(),
            ));
        });
    }
}

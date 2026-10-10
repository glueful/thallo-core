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
     * Save palette keys already validated: `theme_neutral_custom` and `theme_dark_base` in their
     * stored spelling, `theme_brand_colors` as submitted — resolved here, under the row, against the
     * stored list (BrandColors::applied). A colour a running replacement replaces cannot be renamed
     * or re-coloured; a reserved one can; the neutrals are never blocked. The result carries what
     * was written, captured in this transaction (plan ruling 13).
     *
     * @param array<string,string> $pairs
     * @throws PaletteConflict
     * @throws BrandColorsRefused
     */
    public function save(array $pairs, ?string $actor): PaletteSaved
    {
        if ($pairs === []) {
            return new PaletteSaved(false, null);
        }
        return $this->fence->within(function () use ($pairs): PaletteSaved {
            $held = $this->state->lock();
            $brandColors = null;
            if (array_key_exists('theme_brand_colors', $pairs)) {
                $submitted = PaletteSettings::parseSubmitted($pairs['theme_brand_colors'])
                    ?? throw new BrandColorsRefused('a list of brand colours is required');
                // lock() cleared the store's read cache: this is the committed list and its revision.
                [, , $revision] = BrandColors::parse($this->settings->stored('theme_brand_colors'));
                $pairs['theme_brand_colors'] = BrandColors::applied(
                    $held->palette,
                    $revision,
                    $submitted['base'],
                    $submitted['rows'],
                    static fn (int $id): bool => $held->jobReplacing($id) !== null,
                );
                $brandColors = $pairs['theme_brand_colors'];
            }
            $this->settings->save($pairs);
            $changed = $this->state->snapshot()->palette->fingerprint() !== $held->palette->fingerprint();
            if ($changed) {
                $this->state->bump();
            }
            return new PaletteSaved($changed, $brandColors);
        });
    }

    /**
     * Clear a brand colour (custom palette spec §4): refused while a running replacement replaces or
     * writes to the slot, and while any draft, current publication, region, layout, saved section or
     * class names it — scanned inside the transaction, after the bump, so a save that committed first
     * is seen and one racing after is refused by the fence. Historical versions never block.
     *
     * @return string|null the stored list this Clear wrote, captured in its transaction; null when
     *     the id was not a colour
     * @throws BrandColorInUse
     * @throws PaletteConflict
     */
    public function clear(int $slot, ?string $actor): ?string
    {
        $name = null;
        $written = null;
        $this->fence->within(function () use ($slot, &$name, &$written): void {
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
            // Moved to removed, keeping its name (spec §2.3); lock() cleared the store's read cache. What
            // was written is what the response describes (plan ruling 13).
            $written = BrandColors::cleared($this->settings->stored('theme_brand_colors'), $slot);
            $this->settings->save(['theme_brand_colors' => $written]);
            $name = $brand->name;
        });
        if ($name === null) {
            return null;
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
        return $written;
    }
}

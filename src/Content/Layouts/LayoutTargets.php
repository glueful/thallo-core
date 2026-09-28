<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Layouts;

use Thallo\Contracts\Layouts\LayoutSurface;
use Thallo\Core\Content\Blocks\BlockTypeRepository;

/**
 * A surface's targets as the site can use them: what the surface offers, closed while any block of
 * its palette is missing from the site — a site upgraded without `thallo:provision`, or a workspace
 * the block sync has not reached. The list, the editor's session and every save read targets here,
 * so a layout never opens with blocks it cannot place.
 *
 * A saved layout whose target the surface no longer offers — an archive of a field that stopped
 * filing its type — is kept (type layouts spec §6.1); it is listed here as a closed target, so it
 * can still be found and removed (and a field it shows then deleted).
 */
final class LayoutTargets
{
    public const NOT_PROVISIONED = 'Its blocks are not installed yet. Run php glueful thallo:provision on '
        . 'the server — with workspaces on, php glueful thallo:tenant:sync --all --kind=block_type — then '
        . 'reload this page.';

    public const KEPT = 'These pages are not on the site now. The layout is kept until you remove it.';

    public function __construct(
        private readonly BlockTypeRepository $blockTypes,
        /** The saved layouts, for the ones kept at targets no longer offered; null lists offered targets only. */
        private readonly ?LayoutRepository $layouts = null,
    ) {
    }

    /**
     * Each row says whether a layout kept there may be removed while the row is closed (`removable`):
     * a target the surface itself closes — its pages are off the site — or a kept one it no longer
     * offers. A target closed here for missing blocks is still live (the site renders its layout), so
     * it is never removable.
     *
     * @return list<array{
     *     target: string, label: string, enabled: bool, reason: ?string, link: ?string, removable: bool
     * }>
     */
    public function of(LayoutSurface $surface): array
    {
        $rows = array_map(
            static fn (array $row): array => self::checked($surface, $row) + ['removable' => !$row['enabled']],
            $surface->targets(),
        );
        if (!$this->provisioned($surface)) {
            foreach ($rows as $i => $row) {
                if ($row['enabled']) {
                    $rows[$i] = ['enabled' => false, 'reason' => self::NOT_PROVISIONED] + $row;
                }
            }
        }
        if ($this->layouts !== null) {
            $offered = array_flip(array_column($rows, 'target'));
            foreach ($this->layouts->liveTargets($surface->key()) as $target) {
                if (isset($offered[$target])) {
                    continue;
                }
                $kept = [
                    'target' => $target, 'label' => $surface->label($target),
                    'enabled' => false, 'reason' => self::KEPT, 'link' => null, 'removable' => true,
                ];
                // With its own type's rows (a type's archives, `{type}:{field}`), else last.
                $type = explode(':', $target, 2)[0];
                $at = count($rows);
                foreach ($rows as $i => $row) {
                    if ($row['target'] === $type || str_starts_with((string) $row['target'], $type . ':')) {
                        $at = $i + 1;
                    }
                }
                array_splice($rows, $at, 0, [$kept]);
            }
        }
        return $rows;
    }

    /** @return array{target: string, label: string, enabled: bool, reason: ?string, link: ?string, removable: bool}|null */
    public function find(LayoutSurface $surface, string $target): ?array
    {
        foreach ($this->of($surface) as $row) {
            if ($row['target'] === $target) {
                return $row;
            }
        }
        return null;
    }

    /**
     * A surface's row, with every key the contract requires: one left out is named at once, with its
     * surface, rather than warned about on every list.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function checked(LayoutSurface $surface, array $row): array
    {
        foreach (['target', 'label', 'enabled', 'reason', 'link'] as $key) {
            if (!array_key_exists($key, $row)) {
                throw new \LogicException(sprintf(
                    "layout surface '%s': target '%s' has no '%s' (a row carries target, label, enabled, "
                        . 'reason and link)',
                    $surface->key(),
                    (string) ($row['target'] ?? ''),
                    $key,
                ));
            }
        }
        return $row;
    }

    private function provisioned(LayoutSurface $surface): bool
    {
        $palette = $surface->palette();
        if ($palette === []) {
            return true;
        }
        $installed = [];
        foreach ($this->blockTypes->all() as $row) {
            $installed[(string) $row['slug']] = true;
        }
        foreach ($palette as $slug) {
            if (!isset($installed[$slug])) {
                return false;
            }
        }
        return true;
    }
}

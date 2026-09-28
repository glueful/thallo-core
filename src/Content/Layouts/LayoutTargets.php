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
 */
final class LayoutTargets
{
    public const NOT_PROVISIONED = 'Its blocks are not installed yet. Run php glueful thallo:provision on '
        . 'the server — with workspaces on, php glueful thallo:tenant:sync --all --kind=block_type — then '
        . 'reload this page.';

    public function __construct(private readonly BlockTypeRepository $blockTypes)
    {
    }

    /** @return list<array{target: string, label: string, enabled: bool, reason: ?string, link: ?string}> */
    public function of(LayoutSurface $surface): array
    {
        $rows = array_map(static fn (array $row): array => $row + ['link' => null], $surface->targets());
        if (!$this->provisioned($surface)) {
            foreach ($rows as $i => $row) {
                if ($row['enabled']) {
                    $rows[$i] = ['enabled' => false, 'reason' => self::NOT_PROVISIONED] + $row;
                }
            }
        }
        return $rows;
    }

    /** @return array{target: string, label: string, enabled: bool, reason: ?string, link: ?string}|null */
    public function find(LayoutSurface $surface, string $target): ?array
    {
        foreach ($this->of($surface) as $row) {
            if ($row['target'] === $target) {
                return $row;
            }
        }
        return null;
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

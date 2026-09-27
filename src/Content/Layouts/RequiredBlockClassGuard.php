<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Layouts;

use Thallo\Contracts\Layouts\LayoutSurfaceRegistry;

/**
 * A style class edit that would hide a required block (type layouts plan C1): a layout refuses a
 * block holding the product page's Product buy box that is hidden at any size — by its own Visibility
 * or by a class it carries — when it is saved. This keeps that true afterwards: an edit that would
 * make a class carried by such a block hide it is refused, naming the layout.
 */
final class RequiredBlockClassGuard
{
    public function __construct(
        private readonly LayoutRepository $layouts,
        private readonly LayoutSurfaceRegistry $surfaces,
        private readonly LayoutValidator $validator,
    ) {
    }

    /**
     * @param array<string,mixed> $style the class's style as the edit would save it
     * @return string|null why the edit is refused; null when it hides nothing it must not
     */
    public function refusal(string $classId, array $style): ?string
    {
        foreach ($this->layouts->live() as $row) {
            $surface = $this->surfaces->get((string) $row['surface']);
            $blocks = is_array($row['blocks'] ?? null) ? $row['blocks'] : [];
            if ($surface === null || !str_contains((string) json_encode($blocks), '"' . $classId . '"')) {
                continue;
            }
            $hidden = $this->validator->hiddenRequired(
                (string) $row['surface'],
                (string) $row['target'],
                $blocks,
                [$classId => $style],
            );
            foreach ($hidden as $path => $label) {
                if (str_ends_with($path, '.settings.classes')) {
                    return "on the {$surface->label((string) $row['target'])} layout this class is on a block "
                        . "holding the {$label}, which every page shows: it cannot hide it";
                }
            }
        }
        return null;
    }
}

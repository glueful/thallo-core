<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Authorization;

/**
 * Declarative permission-implication data (spec §4.2): which held grants satisfy a
 * required permission. Implications live in catalog data (e.g. `commerce.manage`
 * implies `commerce.view`), never hardcoded in middleware or controllers.
 */
interface PermissionImplicationSource
{
    /**
     * The grants that satisfy `$required`: always includes `$required` itself, plus
     * every declared grant whose transitive implication closure contains it.
     * Deterministic order (required first).
     *
     * @return non-empty-list<string>
     */
    public function satisfiersFor(string $required): array;
}

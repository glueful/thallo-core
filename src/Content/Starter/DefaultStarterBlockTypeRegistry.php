<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Starter;

use Thallo\Contracts\Starter\StarterBlockTypeContributor;
use Thallo\Contracts\Starter\StarterBlockTypeRegistry;

/**
 * In-memory contributor registry. Packs register a {@see StarterBlockTypeContributor} during
 * boot; {@see \Thallo\Core\Content\Starter\Kinds\BlockTypeKind} reads {@see all()} to append converted
 * definitions to the fixed block-type library. Simple append (contributors carry no id to dedupe
 * on, unlike {@see \Thallo\Tenancy\Purge\PurgeResourceRegistry}) — duplicate detection happens at
 * the definition level (sourceId/slug) in BlockTypeKind::definitions().
 */
final class DefaultStarterBlockTypeRegistry implements StarterBlockTypeRegistry
{
    /** @var list<StarterBlockTypeContributor> */
    private array $contributors = [];

    public function register(StarterBlockTypeContributor $contributor): void
    {
        $this->contributors[] = $contributor;
    }

    /** @return list<StarterBlockTypeContributor> */
    public function all(): array
    {
        return $this->contributors;
    }
}

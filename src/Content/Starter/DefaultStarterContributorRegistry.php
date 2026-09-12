<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Starter;

use Thallo\Contracts\Starter\StarterContentTypeContributor;
use Thallo\Contracts\Starter\StarterContributorRegistry;

/**
 * In-memory contributor registry. Packs register a {@see StarterContentTypeContributor} during
 * boot; {@see \Thallo\Core\Content\Starter\Kinds\ContentTypeKind} reads {@see all()} to append converted
 * definitions to the fixed pages/category/post set. Simple append (contributors carry no id to
 * dedupe on, unlike {@see \Thallo\Tenancy\Purge\PurgeResourceRegistry}) — duplicate detection
 * happens at the definition level (sourceId/slug) in ContentTypeKind::definitions().
 */
final class DefaultStarterContributorRegistry implements StarterContributorRegistry
{
    /** @var list<StarterContentTypeContributor> */
    private array $contributors = [];

    public function register(StarterContentTypeContributor $contributor): void
    {
        $this->contributors[] = $contributor;
    }

    /** @return list<StarterContentTypeContributor> */
    public function all(): array
    {
        return $this->contributors;
    }
}

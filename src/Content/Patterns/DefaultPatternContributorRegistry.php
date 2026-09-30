<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Patterns;

use Thallo\Contracts\Patterns\LayoutPatternContributor;
use Thallo\Contracts\Patterns\PatternContributor;
use Thallo\Contracts\Patterns\PatternContributorRegistry;

/**
 * The page library's contributors, with every pattern's identity checked (sections and templates
 * design §3.1). Patterns are indexed by slug and templates name sections by slug, so a slug is
 * unique across {@see StarterPatterns} and every contributor: a collision is refused, naming both
 * owners, instead of one pack silently replacing another's section. A template may name the same
 * contributor's sections or core page sections — never a header or footer section, never one that
 * does not exist. A contributor that breaks either rule is refused whole. Layout patterns share the
 * one slug space, and each names a surface a layout has; a layout template names no sections.
 */
final class DefaultPatternContributorRegistry implements PatternContributorRegistry
{
    /** @var array<string,PatternContributor> */
    private array $contributors = [];

    /** @var array<string,LayoutPatternContributor> */
    private array $layoutContributors = [];

    /** @var array<string,string> slug => owner: 'core' or a contributor's id */
    private array $owners = [];

    public function __construct()
    {
        $core = [
            ...StarterPatterns::sections(),
            ...StarterPatterns::pages(),
            ...StarterPatterns::regionSections(),
            ...StarterPatterns::regionTemplates(),
        ];
        foreach ($core as $pattern) {
            $this->owners[$pattern['slug']] = 'core';
        }
        foreach (array_keys(LayoutPatterns::slugs()) as $slug) {
            $this->owners[$slug] = 'core';
        }
    }

    public function register(PatternContributor $contributor): void
    {
        $id = $contributor->id();
        if (isset($this->contributors[$id])) {
            throw new \LogicException("Pattern contributor '{$id}' is already registered.");
        }

        $claimed = [];
        $own = [];
        foreach ($contributor->sections() as $section) {
            $this->claim($section->slug, $id, $claimed);
            $own[$section->slug] = true;
        }
        $pageSections = array_column(StarterPatterns::sections(), 'slug');
        foreach ($contributor->templates() as $template) {
            $this->claim($template->slug, $id, $claimed);
            if ($template->sections === []) {
                throw new \LogicException("Template '{$template->slug}' of '{$id}' names no sections.");
            }
            foreach ($template->sections as $slug) {
                if (!isset($own[$slug]) && !in_array($slug, $pageSections, true)) {
                    throw new \LogicException(
                        "Template '{$template->slug}' of '{$id}' names '{$slug}', which is not a page section."
                    );
                }
            }
        }

        $this->owners += $claimed;
        $this->contributors[$id] = $contributor;
    }

    public function all(): array
    {
        return array_values($this->contributors);
    }

    public function registerLayout(LayoutPatternContributor $contributor): void
    {
        $id = $contributor->id();
        if (isset($this->layoutContributors[$id])) {
            throw new \LogicException("Layout pattern contributor '{$id}' is already registered.");
        }

        $claimed = [];
        foreach ([...$contributor->layoutSections(), ...$contributor->layoutTemplates()] as $pattern) {
            $this->claim($pattern->slug, $id, $claimed);
            if (!in_array($pattern->surface, LayoutPatterns::SURFACES, true)) {
                throw new \LogicException("Pattern '{$pattern->slug}' of '{$id}' names the surface "
                    . "'{$pattern->surface}', which no layout has.");
            }
        }

        $this->owners += $claimed;
        $this->layoutContributors[$id] = $contributor;
    }

    public function layoutContributors(): array
    {
        return array_values($this->layoutContributors);
    }

    /** Who holds a slug: 'core', a contributor's id, or null when nobody does. */
    public function ownerOf(string $slug): ?string
    {
        return $this->owners[$slug] ?? null;
    }

    /** @param array<string,string> $claimed this contributor's slugs so far */
    private function claim(string $slug, string $id, array &$claimed): void
    {
        $owner = $this->owners[$slug] ?? $claimed[$slug] ?? null;
        if ($owner !== null) {
            throw new \LogicException("Pattern '{$slug}' of '{$id}' is already taken by '{$owner}'.");
        }
        $claimed[$slug] = $id;
    }
}

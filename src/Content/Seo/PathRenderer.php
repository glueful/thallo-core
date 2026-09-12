<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Seo;

final class PathRenderer
{
    public function __construct(
        private readonly string $routeTemplate = '/{locale}/{type}/{slug}',
        private readonly ?string $publicUrlBase = null,
        private readonly string $defaultLocale = 'en'
    ) {
    }

    public function render(string $contentTypeSlug, string $locale, string $slug): string
    {
        return $this->withBase($this->renderTemplate($this->routeTemplate, $contentTypeSlug, $locale, $slug));
    }

    public function renderDefaultLocale(string $contentTypeSlug, string $slug): string
    {
        $template = preg_replace('#/?\{locale\}/?#', '/', $this->routeTemplate) ?? $this->routeTemplate;
        return $this->withBase($this->renderTemplate($template, $contentTypeSlug, $this->defaultLocale, $slug));
    }

    /**
     * Root-mounted grammar (root-mounted-types spec §5): FIXED /{locale}/{slug} —
     * defined by the resolver, never derived from the configured route template
     * (parser and renderer must stay exact inverses). Honors only the public
     * URL base; use {@see renderRootDefaultLocale} for the collapsed form.
     */
    public function renderRoot(string $locale, string $slug): string
    {
        return $this->withBase('/' . rawurlencode($locale) . '/' . rawurlencode($slug));
    }

    /** Root-mounted grammar with the default locale collapsed: /{slug}. */
    public function renderRootDefaultLocale(string $slug): string
    {
        return $this->withBase('/' . rawurlencode($slug));
    }

    private function renderTemplate(string $template, string $contentTypeSlug, string $locale, string $slug): string
    {
        $path = strtr($template, [
            '{type}' => rawurlencode($contentTypeSlug),
            '{locale}' => rawurlencode($locale),
            '{slug}' => rawurlencode($slug),
        ]);
        $path = '/' . trim($path, '/');

        return $path === '/' ? '/' : $path;
    }

    private function withBase(string $path): string
    {
        if ($this->publicUrlBase === null || $this->publicUrlBase === '') {
            return $path;
        }

        return rtrim($this->publicUrlBase, '/') . $path;
    }
}

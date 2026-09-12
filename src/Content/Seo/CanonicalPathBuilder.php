<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Seo;

use Glueful\Extensions\I18n\Contracts\LocaleManagerInterface;

/**
 * THE prefixed-vs-root + default-locale-collapse decision (root-mounted-types
 * spec §5). Every canonical surface — resolver hrefs, nav targets, SEO
 * canonical/hreflang, search index hrefs, sitemap, delivery hrefs, redirect
 * targets — goes through this one method so the surfaces cannot drift.
 */
final class CanonicalPathBuilder
{
    public function __construct(
        private readonly PathRenderer $paths,
        private readonly LocaleManagerInterface $locales,
    ) {
    }

    public function pathFor(string $typeSlug, bool $mountAtRoot, string $locale, string $slug): string
    {
        $isDefault = $locale === $this->locales->default();
        if ($mountAtRoot) {
            return $isDefault
                ? $this->paths->renderRootDefaultLocale($slug)
                : $this->paths->renderRoot($locale, $slug);
        }
        return $isDefault
            ? $this->paths->renderDefaultLocale($typeSlug, $slug)
            : $this->paths->render($typeSlug, $locale, $slug);
    }
}

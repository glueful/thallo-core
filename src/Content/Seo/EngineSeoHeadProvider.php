<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Seo;

use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\RouteRepository;
use Glueful\Bootstrap\ApplicationContext;
use Thallo\Contracts\Delivery\CanonicalPublicOriginResolver;
use Thallo\Contracts\Delivery\HomepageEntryProvider;
use Thallo\Contracts\Delivery\SeoHeadResolver;
use Thallo\Seo\Meta\SeoMetaResolver;

/**
 * The SeoHeadResolver engine implementation (seo-head spec §2): composes the pack's
 * SeoMetaResolver (title/description/og/robots) + CanonicalProjector (canonical/
 * hreflang) + the trusted-origin resolver into the head wire shape. Derives type and
 * slug itself from the entry's route rows — callers supply entryUuid + locale only.
 */
final class EngineSeoHeadProvider implements SeoHeadResolver
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly SeoMetaResolver $meta,
        private readonly CanonicalProjector $canonical,
        private readonly CanonicalPublicOriginResolver $origins,
        private readonly HomepageEntryProvider $homepage,
        private readonly RouteRepository $routes,
        private readonly ContentTypeRepository $types,
    ) {
    }

    public function headFor(string $entryUuid, string $locale): ?array
    {
        // Identity derivation: the route row for THIS locale carries type + slug.
        $route = null;
        foreach ($this->routes->forEntry($entryUuid) as $row) {
            if ((string) $row['locale'] === $locale) {
                $route = $row;
                break;
            }
        }
        if ($route === null) {
            return null; // unrouted variants are never independently rendered
        }
        $typeUuid = (string) $route['content_type_uuid'];
        $slug = (string) $route['slug'];
        $type = $this->types->findByUuid($typeUuid);
        if ($type === null) {
            return null;
        }
        $typeSlug = (string) $type['slug'];

        $meta = $this->meta->resolve($typeUuid, $typeSlug, $slug, $locale);
        if ($meta === null) {
            return null; // not published in this locale
        }

        $origin = $this->safeOrigin();
        $isHomepage = $this->homepage->homepageEntry() === $entryUuid;

        if ($isHomepage) {
            $canonical = $origin !== null ? $origin . '/' : null;
            $alternates = [];
            $xDefault = null;
        } else {
            $projected = $this->canonical->project($entryUuid, $typeUuid, $typeSlug, $locale);
            $canonical = $this->absolute($origin, $projected['canonical']['href'] ?? null);
            $alternates = [];
            foreach ($projected['alternates'] as $alt) {
                $href = $this->absolute($origin, (string) $alt['href']);
                if ($href !== null) {
                    $alternates[] = ['locale' => (string) $alt['locale'], 'href' => $href];
                }
            }
            $xDefault = $this->absolute($origin, $projected['x_default']['href'] ?? null);
        }

        $image = is_string($meta['og']['image'] ?? null) ? $meta['og']['image'] : null;
        if ($image !== null && str_starts_with($image, '/')) {
            $image = $origin !== null ? $origin . $image : null;
        }

        return [
            'title' => (string) $meta['title'],
            'description' => $meta['description'],
            'canonical' => $canonical,
            'alternates' => $alternates,
            'x_default' => $xDefault,
            'og' => [
                'title' => (string) $meta['og']['title'],
                'description' => $meta['og']['description'],
                'image' => $image,
                'url' => $canonical,
                'type' => $isHomepage ? 'website' : 'article',
            ],
            'twitter_card' => is_string($meta['twitter']['card'] ?? null) ? $meta['twitter']['card'] : null,
            'robots' => (string) $meta['robots'],
        ];
    }

    private function safeOrigin(): ?string
    {
        try {
            $origin = rtrim($this->origins->currentOrigin($this->context), '/');
        } catch (\Throwable) {
            return null;
        }
        if ($origin === '') {
            return null;
        }
        // The un-overridden BASE_URL default. An install that never configured its
        // public origin must OMIT canonical/OG URLs (the spec's blank-origin posture),
        // not tell crawlers the site lives at localhost — this fired on a real install
        // (seo-head spec §2). Deliberately the exact default literal: an explicitly
        // configured localhost base (e.g. http://localhost:8080) is a choice and keeps
        // its URLs. Scoped HERE, not in the origin resolver — media URLs and the
        // storefront CSRF origin check must keep working on genuine localhost dev.
        if ($origin === 'http://localhost') {
            return null;
        }
        return $origin;
    }

    private function absolute(?string $origin, ?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }
        // A deployment with `thallo.seo.public_url_base` set makes CanonicalProjector
        // emit ALREADY-ABSOLUTE hrefs (PathRenderer prefixes them). Prefixing again
        // would corrupt the URL — pass them through instead; unifying the two base
        // authorities is the seo-head spec's named follow-up (§7.3).
        if (preg_match('#^https?://#i', $path) === 1) {
            return $path;
        }
        if ($origin === null) {
            return null;
        }
        return $origin . $path;
    }
}

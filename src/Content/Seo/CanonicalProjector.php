<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Seo;

use Thallo\Core\Content\Delivery\DeliveryRepository;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\RouteRepository;

final class CanonicalProjector
{
    public function __construct(
        private readonly DeliveryRepository $delivery,
        private readonly RouteRepository $routes,
        private readonly ContentTypeRepository $types,
        private readonly CanonicalPathBuilder $pathBuilder,
        private readonly string $defaultLocale = 'en'
    ) {
    }

    /** @return array{canonical:?array<string,mixed>,alternates:list<array<string,mixed>>,x_default:?array<string,mixed>} */
    public function project(string $entryUuid, string $contentTypeUuid, string $contentTypeSlug, string $locale): array
    {
        $routes = $this->routes->forEntry($entryUuid);
        $alternates = [];
        $canonical = null;
        $xDefault = null;

        foreach ($this->delivery->publishedPinsForEntry($entryUuid) as $pin) {
            $pinTypeUuid = (string) $pin['type'];
            $pinLocale = (string) $pin['locale'];
            // PER-PIN type lookup: each alternate/x-default href must use its
            // own pin type's mount_at_root — a single caller-supplied flag
            // would mislabel mixed-type alternates (spec review P2).
            $pinType = $this->types->findByUuid($pinTypeUuid);
            $slug = $this->slugFor($routes, $pinTypeUuid, $pinLocale);
            if ($pinType === null || $slug === null) {
                continue;
            }
            $typeSlug = (string) $pinType['slug'];
            $mountAtRoot = (bool) ($pinType['mount_at_root'] ?? false);

            $alternate = [
                'locale' => $pinLocale,
                // Canonical per variant: default locale collapses, root types
                // collapse to /{slug} (the raw locale-prefixed form was the
                // off-canonical pre-builder behavior).
                'href' => $this->pathBuilder->pathFor($typeSlug, $mountAtRoot, $pinLocale, $slug),
                'content_type' => $typeSlug,
                'slug' => $slug,
            ];
            $alternates[] = $alternate;
            if ($pinLocale === $locale) {
                $canonical = $alternate;
            }
            if ($pinLocale === $this->defaultLocale) {
                $xDefault = [
                    'locale' => $pinLocale,
                    'href' => $this->pathBuilder->pathFor($typeSlug, $mountAtRoot, $this->defaultLocale, $slug),
                    'content_type' => $typeSlug,
                    'slug' => $slug,
                ];
            }
        }

        usort(
            $alternates,
            static fn (array $a, array $b): int => strcmp((string) $a['locale'], (string) $b['locale'])
        );

        return [
            'canonical' => $canonical,
            'alternates' => $alternates,
            'x_default' => $xDefault,
        ];
    }

    /** @param list<array<string,mixed>> $routes */
    private function slugFor(array $routes, string $contentTypeUuid, string $locale): ?string
    {
        foreach ($routes as $route) {
            if (
                (string) $route['content_type_uuid'] === $contentTypeUuid
                && (string) $route['locale'] === $locale
            ) {
                return (string) $route['slug'];
            }
        }

        return null;
    }
}

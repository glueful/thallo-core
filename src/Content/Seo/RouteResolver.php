<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Seo;

use Thallo\Core\Content\Delivery\DeliveryRepository;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\RouteRepository;

final class RouteResolver
{
    public function __construct(
        private readonly DeliveryRepository $delivery,
        private readonly RedirectRepository $redirects,
        private readonly RouteRepository $routes,
        private readonly ContentTypeRepository $types,
        private readonly CanonicalPathBuilder $canonical
    ) {
    }

    /** @param list<string> $localeChain */
    public function resolve(
        string $contentTypeUuid,
        string $contentTypeSlug,
        array $localeChain,
        string $slugOrUuid
    ): ?ResolutionResult {
        foreach ($localeChain as $locale) {
            $row = $this->delivery->findPublishedByRoute($contentTypeUuid, $locale, $slugOrUuid);
            if ($row !== null) {
                return ResolutionResult::found($row);
            }
        }

        $requestedLocale = $localeChain[0] ?? 'en';
        $redirect = $this->redirects->findBySource($contentTypeUuid, $requestedLocale, $slugOrUuid);
        if ($redirect !== null) {
            return $this->resolveRedirect($redirect);
        }

        if ($this->looksLikeNanoid($slugOrUuid)) {
            foreach ($localeChain as $locale) {
                $row = $this->delivery->findPublishedByUuid($contentTypeUuid, $locale, $slugOrUuid);
                if ($row !== null) {
                    return ResolutionResult::found($row);
                }
            }
        }

        return null;
    }

    /**
     * Root-grammar redirect fallback (root-mounted-types spec §4): after a
     * root route miss, renames on root-mounted types keep 301ing from /old.
     * At most one row can match — the RootMountGuard keeps root redirect
     * sources globally unique per locale.
     */
    public function resolveRootRedirect(string $locale, string $slug): ?ResolutionResult
    {
        $redirect = $this->redirects->findBySourceAcrossRootTypes($locale, $slug);
        return $redirect === null ? null : $this->resolveRedirect($redirect);
    }

    /** @param array<string,mixed> $redirect */
    private function resolveRedirect(array $redirect): ResolutionResult
    {
        if (isset($redirect['target_url']) && (string) $redirect['target_url'] !== '') {
            return ResolutionResult::moved([
                'uuid' => (string) $redirect['uuid'],
                'to' => (string) $redirect['target_url'],
                'status' => (int) $redirect['status'],
                'external' => $this->isExternalUrl((string) $redirect['target_url']),
                'target_state' => 'live',
                'target' => null,
            ]);
        }

        $targetTypeUuid = (string) $redirect['target_content_type_uuid'];
        $targetLocale = (string) $redirect['target_locale'];
        $targetEntryUuid = (string) $redirect['target_entry_uuid'];
        $targetType = $this->types->findByUuid($targetTypeUuid);
        $targetSlug = $this->slugFor($targetEntryUuid, $targetTypeUuid, $targetLocale);

        $target = [
            'content_type' => $targetType === null ? null : (string) $targetType['slug'],
            'locale' => $targetLocale,
            'slug' => $targetSlug,
        ];

        if ($targetType === null || $targetSlug === null) {
            return ResolutionResult::gone($this->brokenDescriptor($redirect, $target));
        }

        $published = $this->delivery->findPublishedByRoute($targetTypeUuid, $targetLocale, $targetSlug);
        if ($published === null) {
            return ResolutionResult::gone($this->brokenDescriptor($redirect, $target));
        }

        return ResolutionResult::moved([
            'uuid' => (string) $redirect['uuid'],
            // Canonical target: root-collapsed for root-mounted types AND
            // default-locale-collapsed (this previously used the raw prefixed
            // template, sending redirects to off-canonical /en/... URLs).
            'to' => $this->canonical->pathFor(
                (string) $targetType['slug'],
                (bool) ($targetType['mount_at_root'] ?? false),
                $targetLocale,
                $targetSlug,
            ),
            'status' => (int) $redirect['status'],
            'external' => false,
            'target_state' => 'live',
            'target' => $target,
            '_target_entry_uuid' => $targetEntryUuid,
        ]);
    }

    /** @return array<string,mixed> */
    private function brokenDescriptor(array $redirect, array $target): array
    {
        return [
            'uuid' => (string) $redirect['uuid'],
            'to' => null,
            'status' => (int) $redirect['status'],
            'external' => false,
            'target_state' => 'broken',
            'target' => $target,
            '_target_entry_uuid' => (string) $redirect['target_entry_uuid'],
        ];
    }

    private function slugFor(string $entryUuid, string $contentTypeUuid, string $locale): ?string
    {
        foreach ($this->routes->forEntry($entryUuid) as $route) {
            if (
                (string) $route['content_type_uuid'] === $contentTypeUuid
                && (string) $route['locale'] === $locale
            ) {
                return (string) $route['slug'];
            }
        }

        return null;
    }

    private function looksLikeNanoid(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9_-]{12}$/', $value) === 1;
    }

    private function isExternalUrl(string $url): bool
    {
        return str_starts_with($url, 'http://') || str_starts_with($url, 'https://');
    }
}

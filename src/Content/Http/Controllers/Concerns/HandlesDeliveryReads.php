<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\Controllers\Concerns;

use Thallo\Core\Content\Http\DTOs\Requests\Delivery\DeliveryListQuery;
use Thallo\Core\Settings\GeneralSettings;
use Symfony\Component\HttpFoundation\Request;

use function app;

/**
 * Read-side request helpers shared by the delivery controllers (DeliveryController,
 * TaxonomyController): caller scopes, locale/TTL resolution, pagination clamps, and the
 * ETag selection key. Pure moves from DeliveryController — behavior unchanged.
 *
 * Requires the using class to provide readonly props:
 *   - \Glueful\Bootstrap\ApplicationContext $context
 *   - \Glueful\Extensions\I18n\Contracts\LocaleManagerInterface $locales
 */
trait HandlesDeliveryReads
{
    /**
     * The request's granted API-key scopes, or null when the request carries no API key
     * (anonymous). Threaded into reference expansion so a referenced non-public type is
     * gated by the same rule as the URL type ({@see \Thallo\Core\Content\Delivery\DeliveryVisibility}).
     *
     * @return list<string>|null
     */
    private function grantedScopes(Request $request): ?array
    {
        if (!$request->attributes->has('api_key_scopes')) {
            return null;
        }
        return array_values(array_filter(
            (array) $request->attributes->get('api_key_scopes', []),
            'is_string',
        ));
    }

    /**
     * True when the response body depends on an API key's scopes (a private, non-shareable
     * response). Anonymous responses stay publicly cacheable.
     */
    private function isScoped(Request $request): bool
    {
        return $request->attributes->has('api_key_scopes');
    }

    /** @return array{0:int,1:int} */
    private function pageParams(DeliveryListQuery $query): array
    {
        $page = max(1, $query->page ?? 1);
        $perPage = $query->perPage ?? $this->defaultPerPage();
        return [$page, $this->clampPerPage($perPage)];
    }

    /**
     * The page size for the cursor list path: the requested `perPage` (clamped) or the
     * configured default. Doubles as the "is there a next page?" probe in index(), which asks
     * for exactly this many rows and emits a cursor only when the result is full.
     */
    private function limit(DeliveryListQuery $query): int
    {
        $perPage = $query->perPage ?? $this->defaultPerPage();
        return $this->clampPerPage($perPage);
    }

    /**
     * Clamp a requested page size into the safe range: a non-positive value falls back to the
     * default, and the result is capped at `thallo.delivery.max_per_page` so a client cannot
     * request an unbounded page.
     */
    private function clampPerPage(int $perPage): int
    {
        $max = app($this->context, GeneralSettings::class)->maxPerPage();
        if ($perPage < 1) {
            $perPage = $this->defaultPerPage();
        }
        return min($perPage, $max);
    }

    /** The configured default page size (`thallo.delivery.default_per_page`, fallback 20). */
    private function defaultPerPage(): int
    {
        return app($this->context, GeneralSettings::class)->defaultPerPage();
    }

    /**
     * The Cache-Control max-age (seconds) advertised on delivery responses. A content
     * type's `cache_ttl` overrides the global `thallo.delivery.cache_ttl`; null falls back.
     *
     * @param array<string,mixed> $typeRow
     */
    private function ttl(array $typeRow): int
    {
        if (isset($typeRow['cache_ttl'])) {
            return max(0, (int) $typeRow['cache_ttl']);
        }

        return app($this->context, GeneralSettings::class)->cacheTtl();
    }

    /**
     * The locale to read: the `locale` query param when present and non-empty, otherwise the
     * configured i18n default locale.
     */
    private function locale(?string $locale): string
    {
        if ($locale !== null && $locale !== '') {
            return $locale;
        }
        return $this->locales->default();
    }

    /**
     * Read a query param as a string, or null when it is absent or an array (e.g. `key[]=`),
     * so callers can treat it as a plain optional scalar without type juggling.
     */
    private function stringQuery(Request $request, string $key): ?string
    {
        // Read via all(), not get(): InputBag::get() throws a BadRequestException on an array-valued
        // param (`key[]=`) before any is_string() guard could run — a 500 on this public endpoint.
        // all() hands back the raw value so a non-string simply reads as absent (null).
        $value = $request->query->all()[$key] ?? null;
        return is_string($value) ? $value : null;
    }

    /**
     * A stable key for the response shape, so the ETag changes when the requested fields,
     * expansions, sort, filter or locale change.
     */
    private function selectionKey(Request $request): string
    {
        $parts = [
            // Read through stringQuery (all()-based): a `fields[]=`/`expand[]=`/`sort[]=` array param
            // must not throw here — this runs at ETag time on the public delivery path.
            'fields=' . ($this->stringQuery($request, 'fields') ?? ''),
            'expand=' . ($this->stringQuery($request, 'expand') ?? ''),
            'sort=' . ($this->stringQuery($request, 'sort') ?? ''),
            'locale=' . $this->locale($this->stringQuery($request, 'locale')),
            'filter=' . json_encode($request->query->all('filter')),
            // Scoped responses can expand references anonymous callers can't see, so the
            // validator MUST differ by access or a shared cache would 304 a scoped
            // conditional request against an anonymous body.
            'scopes=' . $this->scopeFingerprint($request),
        ];
        return implode('&', $parts);
    }

    /**
     * A stable fingerprint of the caller's access for the ETag key: empty for anonymous,
     * else a hash of the sorted granted scopes so two differently-scoped keys (which may
     * expand different references) never share a validator.
     */
    private function scopeFingerprint(Request $request): string
    {
        $scopes = $this->grantedScopes($request);
        if ($scopes === null) {
            return '';
        }
        sort($scopes);
        return sha1(implode(',', $scopes));
    }
}

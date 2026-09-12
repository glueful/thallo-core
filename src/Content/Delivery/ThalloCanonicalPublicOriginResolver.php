<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Delivery;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantAdministration;
use Glueful\Extensions\Contracts\Tenancy\TenantDomainAdministration;
use RuntimeException;
use Thallo\Contracts\Delivery\CanonicalPublicOriginResolver;
use Thallo\Tenancy\System\SystemFlags;

/**
 * The ONE trusted-origin algorithm (task 6). {@see \Thallo\Core\Content\Media\TenantBlobPublicUrlProvider}
 * delegates its host-selection precedence to {@see originForTenant()} — no other code may
 * duplicate it.
 *
 * `currentOrigin()` never reads the incoming request's `Host` header: while enforcement is
 * active it delegates tenant identity to the shared, request-scoped {@see CurrentTenantResolver}
 * (populated by domain-verified tenant resolution middleware, never raw `Host` trust) and then
 * re-derives the origin from tenant records via {@see originForTenant()}; outside enforcement it
 * falls back to the app's own configured `app.urls.base`. A hostile `Host` can therefore never
 * spoof a canonical origin — either it fails to resolve a tenant at all, or it is silently
 * irrelevant (single-store mode never consults it).
 */
final class ThalloCanonicalPublicOriginResolver implements CanonicalPublicOriginResolver
{
    public function __construct(
        private readonly SystemFlags $flags,
        private readonly ?CurrentTenantResolver $currentTenant,
        private readonly ?TenantAdministration $tenants,
        private readonly ?TenantDomainAdministration $domains,
    ) {
    }

    public function currentOrigin(ApplicationContext $c): string
    {
        if (!$this->flags->enforcementActive()) {
            return $this->normalizedBase($c);
        }

        if ($this->currentTenant === null) {
            throw new RuntimeException(
                'Tenancy enforcement is active but no CurrentTenantResolver is bound.'
            );
        }
        $tenantUuid = $this->currentTenant->tenantUuid($c);
        if ($tenantUuid === '') {
            throw new RuntimeException(
                'Tenancy enforcement is active but no tenant was resolved for this request.'
            );
        }

        return $this->originForTenant($c, $tenantUuid);
    }

    public function originForTenant(ApplicationContext $c, string $tenantUuid): string
    {
        if ($this->tenants === null || $this->domains === null) {
            throw new RuntimeException('Tenant administration contracts are unavailable.');
        }

        $scheme = (string) config($c, 'tenancy.public_origin.scheme', 'https');
        $default = $this->flags->defaultTenantUuid();
        if ($tenantUuid === $default) {
            $hosts = config($c, 'tenancy.public_origin.default_hosts', []);
            if (is_array($hosts) && is_string($hosts[0] ?? null) && $hosts[0] !== '') {
                return $scheme . '://' . $hosts[0];
            }
        }

        foreach ($this->domains->listDomains($c, $tenantUuid) as $domain) {
            if (
                ($domain['verification_status'] ?? '') === 'verified'
                && ($domain['status'] ?? '') === 'active'
                && is_string($domain['host'] ?? null)
            ) {
                return $scheme . '://' . $domain['host'];
            }
        }

        $tenant = $this->tenants->getTenant($c, $tenantUuid);
        $base = config($c, 'tenancy.public_origin.base_domain');
        if (
            $tenant !== null
            && ($tenant['status'] ?? '') === 'active'
            && is_string($base)
            && $base !== ''
        ) {
            return $scheme . '://' . $tenant['slug'] . '.' . $base;
        }

        throw new RuntimeException('Cannot derive the tenant canonical origin.');
    }

    /** Normalized scheme://host[:port] from `app.urls.base`, preserving an explicit non-default port. */
    private function normalizedBase(ApplicationContext $c): string
    {
        $base = (string) config($c, 'app.urls.base', 'http://localhost');
        $parts = parse_url($base);
        if (!is_array($parts) || !isset($parts['scheme']) || !isset($parts['host']) || $parts['host'] === '') {
            throw new RuntimeException("Cannot derive a canonical origin: 'app.urls.base' is not an absolute URL.");
        }

        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        return $origin;
    }
}

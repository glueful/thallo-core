<?php

declare(strict_types=1);

namespace Thallo\Core\Http\Controllers;

use Thallo\Core\Support\ActorHelper;
use Thallo\Core\Support\RoleAuthority;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\TenantDomainAdministration;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;
use Thallo\Contracts\Tenancy\TenancyLifecycleAudit;

final class TenantHostCooldownController
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly RoleAuthority $authority,
        private readonly TenancyLifecycleAudit $audit,
        private readonly ?TenantDomainAdministration $domains = null,
    ) {
    }

    public function override(Request $request): Response
    {
        $actorUuid = ActorHelper::uuidFromRequest($request);
        if ($actorUuid === null || !$this->authority->isCanonicalSuperuser($actorUuid)) {
            return Response::error('Forbidden', Response::HTTP_FORBIDDEN, ['code' => 'FORBIDDEN']);
        }
        if ($this->domains === null) {
            return Response::error('Tenant domain administration is unavailable.', Response::HTTP_SERVICE_UNAVAILABLE);
        }
        $body = json_decode((string) $request->getContent(), true);
        $tenantUuid = is_array($body) && is_string($body['tenant_uuid'] ?? null)
            ? trim($body['tenant_uuid'])
            : '';
        $host = is_array($body) && is_string($body['host'] ?? null) ? trim($body['host']) : '';
        $reason = is_array($body) && is_string($body['reason'] ?? null) ? trim($body['reason']) : '';
        if ($tenantUuid === '' || $host === '' || $reason === '') {
            return Response::validation([
                'override' => 'tenant_uuid, host, and reason are required.',
            ]);
        }

        try {
            $domain = $this->domains->overrideCooldownAndClaim($this->context, $tenantUuid, $host);
            $this->audit->record('host.cooldown_overridden', $actorUuid, $tenantUuid, [
                'host' => strtolower($host),
                'reason' => $reason,
            ]);
            return Response::created($domain + [
                'txt_record' => '_thallo-verify.' . strtolower($host),
            ]);
        } catch (\DomainException | \InvalidArgumentException | \RuntimeException $exception) {
            return Response::error($exception->getMessage(), Response::HTTP_CONFLICT);
        }
    }
}

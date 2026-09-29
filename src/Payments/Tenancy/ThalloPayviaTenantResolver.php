<?php

declare(strict_types=1);

namespace Thallo\Core\Payments\Tenancy;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRequiredException;
use Glueful\Extensions\Payvia\Tenancy\PayviaTenantResolver;
use Psr\Container\ContainerInterface;
use Thallo\Tenancy\Adoption\AdoptionGate;
use Thallo\Tenancy\Resolution\TenancyMode;
use Thallo\Tenancy\Resolution\TenancyModePolicy;
use Thallo\Tenancy\Retrofit\RetrofitInProgressException;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Payments' workspace, from the same three-mode {@see TenancyModePolicy} commerce answers from,
 * so an order and its payments always land in one workspace. Replaces Payvia's own resolver,
 * which failed closed in EVERY mode once a shared resolver was bound — before enforcement, too,
 * where no request carries a workspace, so checkout could not start a payment.
 *
 *   (a) single store  -> '' — holding the {@see AdoptionGate} for the rest of the unit of work, so
 *       the adoption flip can never move this store's rows out from under it. The mode is read
 *       again AFTER the hold is taken: the flip may have committed in between. While a flip holds
 *       the gate, the resolution fails closed (the caller retries after it).
 *   (b) widened       -> the recorded default workspace.
 *   (c) enforced      -> the request's workspace; none is a {@see TenantContextRequiredException},
 *       exactly as Payvia's own fail-closed resolver refuses it.
 */
final class ThalloPayviaTenantResolver implements PayviaTenantResolver
{
    private readonly TenancyModePolicy $policy;

    public function __construct(
        SystemFlags $flags,
        ContainerInterface $container,
        private readonly AdoptionGate $gate,
    ) {
        $this->policy = new TenancyModePolicy($flags, $container);
    }

    public function tenantUuid(ApplicationContext $context): string
    {
        $mode = $this->policy->mode();
        if ($mode === TenancyMode::Sentinel) {
            if (!$this->gate->holdShared()) {
                throw new RetrofitInProgressException();
            }
            $mode = $this->policy->mode();
            if ($mode === TenancyMode::Sentinel) {
                return '';
            }
            $this->gate->release();
        }

        if ($mode === TenancyMode::DefaultTenant) {
            return $this->policy->defaultTenantUuid();
        }

        $tenantUuid = $this->policy->sharedResolver()->tenantUuid($context);
        if ($tenantUuid === '') {
            throw new TenantContextRequiredException('Payvia tenant context is required.');
        }

        return $tenantUuid;
    }
}

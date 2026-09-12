<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Authorization;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;

final class EffectiveRoleMatrix
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly EffectiveRoleEvaluator $evaluator,
        private readonly TenantRoleOverrideRepository $overrides,
        private readonly CapabilityCatalog $catalog,
        private readonly CacheStore $cache,
    ) {
    }

    public function allows(string $tenantUuid, string $role, string $capability): bool
    {
        return in_array($capability, $this->capabilitiesFor($tenantUuid, $role), true);
    }

    /** @return list<string> */
    public function capabilitiesFor(string $tenantUuid, string $role): array
    {
        $key = sprintf(
            'thallo.erm.%s.%d.%s.%s',
            $tenantUuid,
            $this->overrides->policyVersion($tenantUuid),
            $this->catalog->baselinePolicyHash($this->context),
            $role,
        );
        $cached = $this->cache->get($key);
        if (is_array($cached) && array_is_list($cached)) {
            return array_values(array_filter($cached, 'is_string'));
        }
        $effective = $this->evaluator->capabilitiesForUncached($tenantUuid, $role);
        $this->cache->set($key, $effective, 3600);
        return $effective;
    }
}

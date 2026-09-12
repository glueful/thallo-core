<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Authorization;

use Thallo\Contracts\Tenancy\RolePolicyDiagnostics as RolePolicyDiagnosticsContract;

final class RolePolicyDiagnostics implements RolePolicyDiagnosticsContract
{
    public function __construct(private readonly TenantRoleOverrideRepository $overrides)
    {
    }

    public function driftRows(): array
    {
        return $this->overrides->driftRows();
    }
}

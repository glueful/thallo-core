<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Starter;

interface StarterSeedFailpoint
{
    public function afterMarkActive(string $tenantUuid): void;
}

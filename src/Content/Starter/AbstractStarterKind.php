<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Starter;

abstract class AbstractStarterKind implements StarterKind
{
    public function fingerprint(StarterDefinition $definition): string
    {
        return Fingerprint::of($definition->payload);
    }

    public function locateForAdoption(StarterDefinition $definition): ?array
    {
        foreach ([$definition->definitionKey, ...$definition->adoptionKeys] as $key) {
            $located = $this->locateExact($key);
            if ($located !== null) {
                return $located;
            }
        }
        return null;
    }
}

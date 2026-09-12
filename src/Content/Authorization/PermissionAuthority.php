<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Authorization;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Permissions\PermissionManager;

final class PermissionAuthority
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    /** @param array<string,mixed> $context */
    public function can(
        string $userUuid,
        string $permission,
        string $resource,
        array $context,
    ): bool {
        return $this->manager()?->can($userUuid, $permission, $resource, $context) ?? false;
    }

    public function manager(): ?PermissionManager
    {
        if (!$this->context->hasContainer()) {
            return null;
        }

        $container = $this->context->getContainer();
        foreach ([PermissionManager::class, 'permission.manager'] as $id) {
            try {
                if ($container->has($id) && ($manager = $container->get($id)) instanceof PermissionManager) {
                    return $manager;
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }
}

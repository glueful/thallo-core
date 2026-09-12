<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Authorization;

use Glueful\Bootstrap\ApplicationContext;
use Psr\Log\LoggerInterface;

/** Closed tenant-role capability policy shared by HTTP enforcement and the admin UI. */
final class RoleMatrix
{
    /** @var array<string,list<string>> */
    private readonly array $matrix;

    public function __construct(private readonly ApplicationContext $context)
    {
        $configured = config($context, 'tenancy.role_matrix', []);
        $this->matrix = $this->normalize($configured);
    }

    public function allows(string $role, string $capability): bool
    {
        if (!isset($this->matrix[$role]) || !in_array($capability, $this->matrix[$role], true)) {
            $this->warn($role, $capability);
            return false;
        }

        return true;
    }

    /** @return array<string,list<string>> */
    public function capabilities(): array
    {
        return $this->matrix;
    }

    public function isTenantCapability(string $capability): bool
    {
        foreach ($this->matrix as $capabilities) {
            if (in_array($capability, $capabilities, true)) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string,list<string>> */
    private function normalize(mixed $configured): array
    {
        if (!is_array($configured)) {
            return [];
        }

        $matrix = [];
        foreach ($configured as $role => $capabilities) {
            if (!is_string($role) || !is_array($capabilities)) {
                continue;
            }
            $matrix[$role] = array_values(array_unique(array_filter($capabilities, 'is_string')));
        }

        return $matrix;
    }

    private function warn(string $role, string $capability): void
    {
        if (!$this->context->hasContainer()) {
            return;
        }

        try {
            $container = $this->context->getContainer();
            if ($container->has(LoggerInterface::class)) {
                $container->get(LoggerInterface::class)->warning(
                    'Tenant role matrix denied an unknown role/capability pair.',
                    ['role' => $role, 'capability' => $capability],
                );
            }
        } catch (\Throwable) {
            // Authorization remains denied even when warning delivery is unavailable.
        }
    }
}

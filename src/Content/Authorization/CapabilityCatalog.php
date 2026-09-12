<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Authorization;

use Glueful\Bootstrap\ApplicationContext;

final class CapabilityCatalog implements PermissionImplicationSource
{
    public const ALGEBRA_VERSION = 1;

    /** @var array<string, array{label:string,group:string,platform_only:bool,implies?:list<string>}> */
    private const CATALOG = [
        'content.view' => ['label' => 'View content', 'group' => 'Content', 'platform_only' => false],
        'content.create' => ['label' => 'Create content', 'group' => 'Content', 'platform_only' => false],
        'content.edit' => ['label' => 'Edit content', 'group' => 'Content', 'platform_only' => false],
        'content.publish' => ['label' => 'Publish content', 'group' => 'Content', 'platform_only' => false],
        'content.delete' => ['label' => 'Delete content', 'group' => 'Content', 'platform_only' => false],
        'content.manage' => ['label' => 'Manage content models', 'group' => 'Content', 'platform_only' => false],
        'content.routes' => ['label' => 'Manage routes', 'group' => 'Content', 'platform_only' => false],
        'navigation.manage' => ['label' => 'Manage navigation', 'group' => 'Experience', 'platform_only' => false],
        'seo.manage' => ['label' => 'Manage SEO', 'group' => 'Experience', 'platform_only' => false],
        'templates.manage' => ['label' => 'Manage templates', 'group' => 'Experience', 'platform_only' => false],
        'analytics.read' => ['label' => 'View analytics', 'group' => 'Operations', 'platform_only' => false],
        'workflow.review' => ['label' => 'Review workflow', 'group' => 'Operations', 'platform_only' => false],
        'tenant.members.manage' => ['label' => 'Manage members', 'group' => 'Workspace', 'platform_only' => false],
        'tenant.domains.manage' => ['label' => 'Manage domains', 'group' => 'Workspace', 'platform_only' => false],
        'tenant.roles.manage' => ['label' => 'Manage roles', 'group' => 'Workspace', 'platform_only' => false],
        'collections.manage' => ['label' => 'Manage collections', 'group' => 'Collections', 'platform_only' => false],
        'collections.schema.manage' => [
            'label' => 'Manage collection schemas', 'group' => 'Collections', 'platform_only' => false,
        ],
        'collections.data.manage' => [
            'label' => 'Manage collection data', 'group' => 'Collections', 'platform_only' => false,
        ],
        'commerce.view' => ['label' => 'View commerce', 'group' => 'Commerce', 'platform_only' => false],
        'commerce.manage' => [
            'label' => 'Manage commerce', 'group' => 'Commerce', 'platform_only' => false,
            'implies' => ['commerce.view'],
        ],
        'billing.manage' => ['label' => 'Manage billing', 'group' => 'Workspace', 'platform_only' => false],
    ];

    /** @return array<string, array{label:string,group:string,platform_only:bool,implies?:list<string>}> */
    public function all(): array
    {
        return self::CATALOG;
    }

    public function has(string $slug): bool
    {
        return isset(self::CATALOG[$slug]);
    }

    public function isGrantable(string $slug): bool
    {
        return isset(self::CATALOG[$slug]) && !self::CATALOG[$slug]['platform_only'];
    }

    /** @return list<string> */
    public function ownerFloor(): array
    {
        return ['tenant.roles.manage', 'tenant.members.manage'];
    }

    /** @return list<string> */
    public function reservedRoles(): array
    {
        return ['owner', 'admin', 'member', 'viewer'];
    }

    /**
     * {@see PermissionImplicationSource}: `$required` itself plus every catalog grant
     * whose transitive `implies` closure contains it, in catalog declaration order.
     *
     * @return non-empty-list<string>
     */
    public function satisfiersFor(string $required): array
    {
        return self::computeSatisfiers(self::CATALOG, $required);
    }

    /**
     * Pure implication-graph resolution, parameterized over an arbitrary catalog map so
     * the cycle/unknown-target validation pass is unit-testable without mutating the
     * production CATALOG constant.
     *
     * @param array<string, array{implies?: list<string>}> $catalog
     * @return non-empty-list<string>
     */
    public static function computeSatisfiers(array $catalog, string $required): array
    {
        self::assertValidImplications($catalog);

        $satisfiers = [$required];
        foreach (array_keys($catalog) as $slug) {
            if ($slug !== $required && self::impliesTransitively($catalog, $slug, $required)) {
                $satisfiers[] = $slug;
            }
        }

        return $satisfiers;
    }

    public function baselinePolicyHash(ApplicationContext $context): string
    {
        return self::hashPayload($this->payload($context));
    }

    /** @return array<string,mixed> */
    public function payload(ApplicationContext $context): array
    {
        $matrix = config($context, 'tenancy.role_matrix', []);
        return self::canonicalize([
            'algebra_version' => self::ALGEBRA_VERSION,
            'reserved_roles' => $this->reservedRoles(),
            'owner_floor' => $this->ownerFloor(),
            'catalog' => self::CATALOG,
            'role_matrix' => is_array($matrix) ? $matrix : [],
        ]);
    }

    /** @param array<string,mixed> $payload */
    public static function hashPayload(array $payload): string
    {
        return hash('sha256', json_encode(self::canonicalize($payload), JSON_THROW_ON_ERROR));
    }

    /**
     * Does `$from` transitively imply `$target` (depth-first over the `implies` edges)?
     * Cycle safety is guaranteed by {@see assertValidImplications} having already run.
     *
     * @param array<string, array{implies?: list<string>}> $catalog
     */
    private static function impliesTransitively(array $catalog, string $from, string $target): bool
    {
        foreach ($catalog[$from]['implies'] ?? [] as $next) {
            if ($next === $target || self::impliesTransitively($catalog, $next, $target)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Validates the implication graph: every `implies` target must be a declared catalog
     * entry, and the graph must be acyclic. Throws loudly (fail-fast on a bad catalog)
     * rather than silently mis-resolving effective policy.
     *
     * @param array<string, array{implies?: list<string>}> $catalog
     */
    private static function assertValidImplications(array $catalog): void
    {
        foreach ($catalog as $slug => $entry) {
            foreach ($entry['implies'] ?? [] as $target) {
                if (!isset($catalog[$target])) {
                    throw new \LogicException(
                        "Capability '{$slug}' implies unknown capability '{$target}'.",
                    );
                }
            }
        }
        foreach (array_keys($catalog) as $slug) {
            self::assertNoCycleFrom($catalog, $slug, $slug, []);
        }
    }

    /**
     * @param array<string, array{implies?: list<string>}> $catalog
     * @param list<string> $path
     */
    private static function assertNoCycleFrom(array $catalog, string $origin, string $current, array $path): void
    {
        foreach ($catalog[$current]['implies'] ?? [] as $next) {
            if ($next === $origin) {
                throw new \LogicException(
                    "Capability implication cycle detected involving '{$origin}'.",
                );
            }
            if (in_array($next, $path, true)) {
                continue; // a different cycle, caught when $origin is one of its own members
            }
            self::assertNoCycleFrom($catalog, $origin, $next, [...$path, $current]);
        }
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            $items = array_map(self::canonicalize(...), $value);
            sort($items);
            return $items;
        }
        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = self::canonicalize($item);
        }
        return $value;
    }
}

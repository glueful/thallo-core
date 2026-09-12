<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Starter\Kinds;

use Thallo\Core\Content\Starter\AbstractStarterKind;
use Thallo\Core\Content\Starter\Fingerprint;
use Thallo\Core\Content\Starter\SeedContext;
use Thallo\Core\Content\Starter\StarterApplyResult;
use Thallo\Core\Content\Starter\StarterDefinition;
use Glueful\Database\Connection;
use Glueful\Helpers\Utils;

final class NavigationMenuKind extends AbstractStarterKind
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function kind(): string
    {
        return 'navigation_menu';
    }

    public function definitions(): array
    {
        return [new StarterDefinition('navigation_menu:main', 'main', [
            'name' => 'Main',
            'items' => [['label' => 'Home', 'url' => '/', 'position' => 0]],
        ])];
    }

    public function locateExact(string $definitionKey): ?array
    {
        $menu = $this->db->table('navigation_menus')->where('slug', '=', $definitionKey)->first();
        if ($menu === null) {
            return null;
        }
        $items = $this->db->table('navigation_items')
            ->where('menu_uuid', '=', (string) $menu['uuid'])
            ->orderBy('position', 'ASC')
            ->get();
        return [
            'key' => $definitionKey,
            'fingerprint' => Fingerprint::of([
                'name' => (string) $menu['name'],
                'items' => array_map(static fn(array $item): array => [
                    'label' => (string) (array_values((array) json_decode((string) $item['labels'], true))[0] ?? ''),
                    'url' => (string) ($item['url'] ?? ''),
                    'position' => (int) $item['position'],
                ], $items),
            ]),
        ];
    }

    public function apply(StarterDefinition $definition, SeedContext $seed): StarterApplyResult
    {
        if ($this->locateExact($definition->definitionKey) !== null) {
            return StarterApplyResult::SkippedCollision;
        }
        $now = gmdate('Y-m-d H:i:s');
        $menuUuid = Utils::generateNanoID(12);
        $this->db->table('navigation_menus')->insert([
            'uuid' => $menuUuid,
            'slug' => $definition->definitionKey,
            'name' => (string) $definition->payload['name'],
            'lock_version' => 0,
            'position' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ($definition->payload['items'] as $item) {
            $this->db->table('navigation_items')->insert([
                'uuid' => Utils::generateNanoID(12),
                'menu_uuid' => $menuUuid,
                'parent_uuid' => null,
                'position' => (int) $item['position'],
                'kind' => 'url',
                'entry_uuid' => null,
                'url' => (string) $item['url'],
                'icon' => null,
                'labels' => json_encode([$seed->defaultLocale => $item['label']], JSON_THROW_ON_ERROR),
                'descriptions' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        return StarterApplyResult::Applied;
    }

    public function updateTo(StarterDefinition $definition, string $rowKey, SeedContext $seed): void
    {
        throw new \LogicException('Starter navigation menus are seed-only.');
    }

    public function rename(StarterDefinition $definition, string $oldKey): void
    {
        throw new \LogicException('Starter navigation menus are seed-only.');
    }

    public function syncable(): bool
    {
        return false;
    }
}

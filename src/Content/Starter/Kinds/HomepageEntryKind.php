<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Starter\Kinds;

use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Repositories\RouteRepository;
use Thallo\Core\Content\Services\PublishService;
use Thallo\Core\Content\Starter\AbstractStarterKind;
use Thallo\Core\Content\Starter\SeedContext;
use Thallo\Core\Content\Starter\StarterApplyResult;
use Thallo\Core\Content\Starter\StarterDefinition;
use Thallo\Core\Settings\SettingsStore;
use Glueful\Database\Connection;

final class HomepageEntryKind extends AbstractStarterKind
{
    public function __construct(
        private readonly ContentTypeRepository $types,
        private readonly EntryRepository $entries,
        private readonly RouteRepository $routes,
        private readonly PublishService $publisher,
        private readonly SettingsStore $settings,
        private readonly Connection $db,
    ) {
    }

    public function kind(): string
    {
        return 'entry';
    }

    public function definitions(): array
    {
        return [new StarterDefinition('entry:homepage', '/', [
            'type' => 'pages',
            'route' => 'home',
            'title' => 'Welcome',
        ])];
    }

    public function locateExact(string $definitionKey): ?array
    {
        $configured = $this->settings->get('homepage_entry');
        if (is_string($configured) && $configured !== '') {
            return ['key' => '/', 'fingerprint' => 'tenant-authored'];
        }
        $type = $this->types->findBySlug('pages');
        if ($type === null) {
            return null;
        }
        $route = $this->db->table('entry_routes')
            ->where('content_type_uuid', '=', (string) $type['uuid'])
            ->where('slug', '=', 'home')
            ->first();
        return $route === null ? null : ['key' => '/', 'fingerprint' => 'tenant-authored'];
    }

    public function apply(StarterDefinition $definition, SeedContext $seed): StarterApplyResult
    {
        if ($this->settings->get('homepage_entry') !== null) {
            return StarterApplyResult::SkippedCollision;
        }
        $type = $this->types->findBySlug((string) $definition->payload['type'])
            ?? throw new \RuntimeException('Starter pages content type is missing.');
        if ($this->routes->findBySlug((string) $type['uuid'], $seed->defaultLocale, 'home') !== null) {
            return StarterApplyResult::SkippedCollision;
        }
        $entryUuid = $this->entries->createEntry(
            (string) $type['uuid'],
            $seed->defaultLocale,
            (int) $type['schema_version'],
            $seed->actorUuid,
        );
        $this->entries->saveDraft($entryUuid, $seed->defaultLocale, [
            'title' => $definition->payload['title'] . ' to ' . $seed->tenantName,
            'body' => [[
                'id' => 'starterhome',
                'type' => 'rich_text',
                'data' => ['body' => '<p>Your new Thallo site is ready.</p>'],
            ]],
        ], (int) $type['schema_version'], 0, $seed->actorUuid);
        $this->routes->assign($entryUuid, (string) $type['uuid'], $seed->defaultLocale, 'home');
        $this->publisher->publishStarter($entryUuid, $seed->defaultLocale, $seed->actorUuid);
        $this->settings->putMany(['homepage_entry' => $entryUuid]);
        return StarterApplyResult::Applied;
    }

    public function updateTo(StarterDefinition $definition, string $rowKey, SeedContext $seed): void
    {
        throw new \LogicException('Starter entries are seed-only.');
    }

    public function rename(StarterDefinition $definition, string $oldKey): void
    {
        throw new \LogicException('Starter entries are seed-only.');
    }

    public function syncable(): bool
    {
        return false;
    }
}

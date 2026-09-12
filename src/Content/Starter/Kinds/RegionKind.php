<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Starter\Kinds;

use Thallo\Core\Content\Regions\RegionRepository;
use Thallo\Core\Content\Starter\AbstractStarterKind;
use Thallo\Core\Content\Starter\Fingerprint;
use Thallo\Core\Content\Starter\SeedContext;
use Thallo\Core\Content\Starter\StarterApplyResult;
use Thallo\Core\Content\Starter\StarterDefinition;
use Thallo\Core\Settings\GeneralSettings;
use Glueful\Database\Connection;
use Glueful\Helpers\Utils;

final class RegionKind extends AbstractStarterKind
{
    public function __construct(
        private readonly RegionRepository $regions,
        private readonly GeneralSettings $settings,
        private readonly Connection $db,
    ) {
    }

    public function kind(): string
    {
        return 'region';
    }

    public function definitions(): array
    {
        return [
            new StarterDefinition('region:header', 'header', [
                'blocks' => [
                    ['type' => 'logo', 'data' => ['size' => 'medium', 'link_home' => true]],
                    ['type' => 'navigation', 'data' => ['menu' => 'main']],
                ],
                'settings' => ['sticky' => false, 'width' => 'contained'],
            ]),
            new StarterDefinition('region:footer', 'footer', [
                'blocks' => [[
                    'type' => 'rich_text',
                    'data' => ['body' => '<p>{{site_name}}</p>'],
                ]],
                'settings' => ['width' => 'contained'],
            ]),
        ];
    }

    public function fingerprint(StarterDefinition $definition): string
    {
        return Fingerprint::of($this->resolve($definition->payload, $this->settings->siteName()));
    }

    public function locateExact(string $definitionKey): ?array
    {
        $row = $this->regions->find($definitionKey);
        if ($row === null) {
            return null;
        }
        return [
            'key' => $definitionKey,
            'fingerprint' => Fingerprint::of([
                'blocks' => $this->stripIds($row['blocks']),
                'settings' => $row['settings'],
            ]),
        ];
    }

    public function apply(StarterDefinition $definition, SeedContext $seed): StarterApplyResult
    {
        if ($this->regions->find($definition->definitionKey) !== null) {
            return StarterApplyResult::SkippedCollision;
        }
        $this->save($definition, $seed);
        return StarterApplyResult::Applied;
    }

    public function updateTo(
        StarterDefinition $definition,
        string $rowKey,
        SeedContext $seed,
    ): void {
        $this->save($definition, $seed, $rowKey);
    }

    public function rename(StarterDefinition $definition, string $oldKey): void
    {
        $this->db->table('regions')->where('slug', '=', $oldKey)->update([
            'slug' => $definition->definitionKey,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function syncable(): bool
    {
        return true;
    }

    private function save(StarterDefinition $definition, SeedContext $seed, ?string $key = null): void
    {
        $payload = $this->resolve($definition->payload, $seed->tenantName);
        $blocks = array_map(static fn(array $block): array => [
            'id' => Utils::generateNanoID(12),
            ...$block,
        ], $payload['blocks']);
        $this->regions->save(
            $key ?? $definition->definitionKey,
            $blocks,
            $payload['settings'],
            $seed->actorUuid,
        );
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function resolve(array $payload, string $siteName): array
    {
        foreach ($payload['blocks'] as &$block) {
            $body = $block['data']['body'] ?? null;
            if (is_string($body)) {
                $block['data']['body'] = str_replace(
                    '{{site_name}}',
                    htmlspecialchars($siteName, ENT_QUOTES),
                    $body,
                );
            }
        }
        unset($block);
        return $payload;
    }

    /** @param list<array<string,mixed>> $blocks @return list<array<string,mixed>> */
    private function stripIds(array $blocks): array
    {
        return array_map(static function (array $block): array {
            unset($block['id']);
            return $block;
        }, $blocks);
    }
}

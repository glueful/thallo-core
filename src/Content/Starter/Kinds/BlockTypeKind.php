<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Starter\Kinds;

use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Core\Content\Blocks\StarterBlockTypes;
use Thallo\Core\Content\Schema\SchemaParseException;
use Thallo\Core\Content\Starter\AbstractStarterKind;
use Thallo\Core\Content\Starter\Fingerprint;
use Thallo\Core\Content\Starter\SeedContext;
use Thallo\Core\Content\Starter\StarterApplyResult;
use Thallo\Core\Content\Starter\StarterDefinition;
use Glueful\Database\Connection;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Contracts\Starter\StarterBlockTypeDefinition;
use Thallo\Contracts\Starter\StarterBlockTypeRegistry;

final class BlockTypeKind extends AbstractStarterKind
{
    /**
     * @param CapabilityRegistry|null $capabilities Applies each contribution's
     *        {@see StarterBlockTypeDefinition::$requiresCapability}: a gated definition is part
     *        of {@see definitions()} only while its capability is on, and is reported by
     *        {@see hiddenSlugs()} while it is off. Without a registry nothing is gated.
     */
    public function __construct(
        private readonly BlockTypeRepository $blocks,
        private readonly Connection $db,
        private readonly ?StarterBlockTypeRegistry $contributors = null,
        private readonly ?CapabilityRegistry $capabilities = null,
    ) {
    }

    public function kind(): string
    {
        return 'block_type';
    }

    public function definitions(): array
    {
        $fixed = array_map(static function (array $definition): StarterDefinition {
            $definition['active'] = (bool) ($definition['active'] ?? true);
            return new StarterDefinition(
                'block_type:' . $definition['slug'],
                (string) $definition['slug'],
                $definition,
            );
        }, StarterBlockTypes::definitions());

        $contributions = $this->contributions();
        // Duplicates are checked over EVERY contribution, gated or not: a pack colliding with
        // the fixed library is a configuration error whatever its switch says today.
        $this->assertNoDuplicates([...$fixed, ...array_column($contributions, 'definition')]);

        $enabled = [];
        foreach ($contributions as $contribution) {
            if ($this->isOn($contribution['capability'])) {
                $enabled[] = $contribution['definition'];
            }
        }

        return [...$fixed, ...$enabled];
    }

    /**
     * Slugs of contributed block types whose capability is currently off. Their rows (seeded
     * while the capability was on) stay in the table — an admin's edits and the content that
     * references them survive the switch — but Settings › Block types and the picker leave
     * them out until the capability is on again.
     *
     * @return list<string>
     */
    public function hiddenSlugs(): array
    {
        $hidden = [];
        foreach ($this->contributions() as $contribution) {
            if (!$this->isOn($contribution['capability'])) {
                $hidden[] = $contribution['definition']->definitionKey;
            }
        }
        return $hidden;
    }

    /**
     * Every contribution gated by `$capability`, whatever its current state — what the first
     * boot after that capability turns on seeds.
     *
     * @return list<StarterDefinition>
     */
    public function contributionsFor(string $capability): array
    {
        $definitions = [];
        foreach ($this->contributions() as $contribution) {
            if ($contribution['capability'] === $capability) {
                $definitions[] = $contribution['definition'];
            }
        }
        return $definitions;
    }

    /** @return list<string> distinct capability ids that gate at least one contribution */
    public function gatedCapabilities(): array
    {
        $capabilities = [];
        foreach ($this->contributions() as $contribution) {
            if ($contribution['capability'] !== null) {
                $capabilities[$contribution['capability']] = true;
            }
        }
        return array_keys($capabilities);
    }

    private function isOn(?string $capability): bool
    {
        if ($capability === null || $this->capabilities === null) {
            return true;
        }
        return $this->capabilities->isEnabled($capability);
    }

    public function fingerprint(StarterDefinition $definition): string
    {
        $payload = $definition->payload;
        unset($payload['slug']);
        return Fingerprint::of($payload);
    }

    public function locateExact(string $definitionKey): ?array
    {
        $row = $this->blocks->findBySlug($definitionKey);
        return $row === null ? null : [
            'key' => $definitionKey,
            'fingerprint' => Fingerprint::of($this->normalizeRow($row)),
        ];
    }

    public function apply(StarterDefinition $definition, SeedContext $seed): StarterApplyResult
    {
        if ($this->blocks->findBySlug($definition->definitionKey) !== null) {
            return StarterApplyResult::SkippedCollision;
        }
        $this->blocks->create($definition->payload);
        return StarterApplyResult::Applied;
    }

    public function updateTo(
        StarterDefinition $definition,
        string $rowKey,
        SeedContext $seed,
    ): void {
        $row = $this->blocks->findBySlug($rowKey)
            ?? throw new \RuntimeException("block type {$rowKey} not found");
        $payload = $definition->payload;
        $this->blocks->updateSchema(
            (string) $row['uuid'],
            $payload['schema'],
            (string) $payload['label'],
            isset($payload['icon']) ? (string) $payload['icon'] : null,
            isset($payload['description']) ? (string) $payload['description'] : null,
            isset($payload['category']) ? (string) $payload['category'] : null,
        );
        if ((bool) $row['active'] !== (bool) $payload['active']) {
            $this->blocks->setActive((string) $row['uuid'], (bool) $payload['active']);
        }
    }

    public function rename(StarterDefinition $definition, string $oldKey): void
    {
        $this->db->table('block_types')->where('slug', '=', $oldKey)->update([
            'slug' => $definition->definitionKey,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
        $this->blocks->resetSchemaMemo();
    }

    public function syncable(): bool
    {
        return true;
    }

    /**
     * Converted contributed definitions, in registration/contributor order, each paired with the
     * capability that gates it (null = ungated). Each contributor's VOs are validated and
     * converted to the internal {@see StarterDefinition} shape BEFORE this method returns —
     * nothing here or downstream (TenantSeeder/StarterSync) writes to storage until the full
     * fixed+contributed set has been assembled and passed the duplicate check in
     * {@see definitions()}. The capability is kept beside the definition, not in its payload:
     * the payload is fingerprinted for drift detection and mirrors the row exactly.
     *
     * @return list<array{definition: StarterDefinition, capability: ?string}>
     */
    private function contributions(): array
    {
        $contributions = [];
        foreach ($this->contributors?->all() ?? [] as $contributor) {
            foreach ($contributor->blockTypeDefinitions() as $definition) {
                $capability = $definition->requiresCapability === null
                    ? null
                    : trim($definition->requiresCapability);
                $contributions[] = [
                    'definition' => $this->convert($definition),
                    'capability' => $capability === '' ? null : $capability,
                ];
            }
        }
        return $contributions;
    }

    private function convert(StarterBlockTypeDefinition $definition): StarterDefinition
    {
        $sourceId = trim($definition->sourceId);
        if ($sourceId === '') {
            throw new \InvalidArgumentException('starter block-type contribution has an empty sourceId');
        }
        $slug = trim($definition->slug);
        if ($slug === '') {
            throw new \InvalidArgumentException("starter block-type contribution '{$sourceId}' has an empty slug");
        }
        $label = trim($definition->label);
        if ($label === '') {
            throw new \InvalidArgumentException("starter block-type contribution '{$sourceId}' has an empty label");
        }

        // Same rule the fixed library satisfies (BlockTypeRepository::create()/updateSchema()):
        // no blocks/localized/filterable prohibitions plus full field-schema parsing.
        try {
            $this->blocks->assertBlockSchema($definition->schema);
        } catch (SchemaParseException $e) {
            throw new SchemaParseException(
                "starter block-type contribution '{$sourceId}' has an invalid schema: " . $e->getMessage(),
                previous: $e,
            );
        }

        return new StarterDefinition(
            sourceId: $definition->sourceId,
            definitionKey: $slug,
            payload: [
                'slug' => $slug,
                'label' => $label,
                'icon' => $definition->icon,
                'category' => $definition->category,
                'description' => $definition->description,
                'schema' => $definition->schema,
                'active' => true,
            ],
        );
    }

    /** @param list<StarterDefinition> $definitions */
    private function assertNoDuplicates(array $definitions): void
    {
        $seenSourceIds = [];
        $seenSlugs = [];
        foreach ($definitions as $definition) {
            if (isset($seenSourceIds[$definition->sourceId])) {
                throw new \InvalidArgumentException(
                    "duplicate starter block-type sourceId '{$definition->sourceId}'"
                );
            }
            $seenSourceIds[$definition->sourceId] = true;

            if (isset($seenSlugs[$definition->definitionKey])) {
                throw new \InvalidArgumentException(
                    "duplicate starter block-type slug '{$definition->definitionKey}'"
                );
            }
            $seenSlugs[$definition->definitionKey] = true;
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalizeRow(array $row): array
    {
        return [
            'label' => (string) $row['label'],
            'icon' => $row['icon'] === null ? null : (string) $row['icon'],
            'category' => $row['category'] === null ? null : (string) $row['category'],
            'description' => $row['description'] === null ? null : (string) $row['description'],
            'schema' => (array) $row['schema'],
            'active' => (bool) $row['active'],
        ];
    }
}

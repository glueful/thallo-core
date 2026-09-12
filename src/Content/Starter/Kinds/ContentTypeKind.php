<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Starter\Kinds;

use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Content\Schema\SchemaParseException;
use Thallo\Core\Content\Starter\AbstractStarterKind;
use Thallo\Core\Content\Starter\Fingerprint;
use Thallo\Core\Content\Starter\SeedContext;
use Thallo\Core\Content\Starter\StarterApplyResult;
use Thallo\Core\Content\Starter\StarterDefinition;
use Glueful\Database\Connection;
use Thallo\Contracts\Starter\StarterContentTypeDefinition;
use Thallo\Contracts\Starter\StarterContributorRegistry;

final class ContentTypeKind extends AbstractStarterKind
{
    public function __construct(
        private readonly ContentTypeRepository $types,
        private readonly Connection $db,
        private readonly ?StarterContributorRegistry $contributors = null,
    ) {
    }

    public function kind(): string
    {
        return 'content_type';
    }

    public function definitions(): array
    {
        $fixed = array_map(fn(array $payload): StarterDefinition => new StarterDefinition(
            sourceId: 'content_type:' . $payload['slug'],
            definitionKey: (string) $payload['slug'],
            payload: $payload,
        ), $this->payloads());

        $definitions = [...$fixed, ...$this->contributedDefinitions()];
        $this->assertNoDuplicates($definitions);

        return $definitions;
    }

    public function fingerprint(StarterDefinition $definition): string
    {
        $payload = $definition->payload;
        unset($payload['slug']);
        return Fingerprint::of($payload);
    }

    public function locateExact(string $definitionKey): ?array
    {
        $row = $this->types->findBySlug($definitionKey);
        return $row === null ? null : [
            'key' => $definitionKey,
            'fingerprint' => Fingerprint::of($this->normalizeRow($row)),
        ];
    }

    public function apply(StarterDefinition $definition, SeedContext $seed): StarterApplyResult
    {
        if ($this->types->findBySlug($definition->definitionKey) !== null) {
            return StarterApplyResult::SkippedCollision;
        }
        $this->types->create($definition->payload + ['created_by' => $seed->actorUuid]);
        return StarterApplyResult::Applied;
    }

    public function updateTo(
        StarterDefinition $definition,
        string $rowKey,
        SeedContext $seed,
    ): void {
        $row = $this->types->findBySlug($rowKey)
            ?? throw new \RuntimeException("content type {$rowKey} not found");
        if ($row['schema'] !== $definition->payload['schema']) {
            $this->types->updateSchema((string) $row['uuid'], $definition->payload['schema']);
        }
        $this->types->updateMeta((string) $row['uuid'], [
            'name' => $definition->payload['name'],
            'description' => $definition->payload['description'],
            'cache_ttl' => $definition->payload['cache_ttl'],
            'public_delivery' => $definition->payload['public_delivery'],
            'mount_at_root' => $definition->payload['mount_at_root'],
        ]);
    }

    public function rename(StarterDefinition $definition, string $oldKey): void
    {
        $this->db->table('content_types')->where('slug', '=', $oldKey)->update([
            'slug' => $definition->definitionKey,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function syncable(): bool
    {
        return true;
    }

    /** @return list<array<string,mixed>> */
    private function payloads(): array
    {
        return [
            // body is optional: a page must be publishable with a title alone (a landing page
            // composed in the designer, a placeholder, a stub) — the first-run Publish must not 422.
            $this->payload('pages', 'Pages', 'Generic static pages (e.g. About, Contact).', true, true, [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'body', 'type' => 'blocks'],
            ]),
            $this->payload('category', 'Categories', 'Groups posts into browsable archives.', true, false, [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'slug', 'type' => 'string', 'required' => true],
            ]),
            $this->payload('post', 'Posts', 'Dated articles and news (e.g. blog posts).', true, false, [
                ['name' => 'title', 'type' => 'string', 'required' => true],
                ['name' => 'excerpt', 'type' => 'text'],
                ['name' => 'cover', 'type' => 'asset'],
                ['name' => 'body', 'type' => 'blocks'],
                [
                    'name' => 'categories', 'type' => 'reference', 'reference_type' => 'category',
                    'multiple' => true, 'filterable' => true,
                ],
            ]),
        ];
    }

    /** @param list<array<string,mixed>> $schema @return array<string,mixed> */
    private function payload(
        string $slug,
        string $name,
        string $description,
        bool $public,
        bool $root,
        array $schema,
    ): array {
        return [
            'slug' => $slug,
            'name' => $name,
            'description' => $description,
            'cache_ttl' => null,
            'public_delivery' => $public,
            'mount_at_root' => $root,
            'schema' => ContentTypeSchema::fromArray($schema)->toArray(),
        ];
    }

    /**
     * Converted contributed definitions, in registration/contributor order. Each contributor's
     * VOs are validated and converted to the internal {@see StarterDefinition} shape BEFORE this
     * method returns — nothing here or downstream (TenantSeeder/StarterSync) writes to storage
     * until the full fixed+contributed set has been assembled and passed the duplicate check in
     * {@see definitions()}.
     *
     * @return list<StarterDefinition>
     */
    private function contributedDefinitions(): array
    {
        $definitions = [];
        foreach ($this->contributors?->all() ?? [] as $contributor) {
            foreach ($contributor->contentTypeDefinitions() as $definition) {
                $definitions[] = $this->convert($definition);
            }
        }
        return $definitions;
    }

    private function convert(StarterContentTypeDefinition $definition): StarterDefinition
    {
        $sourceId = trim($definition->sourceId);
        if ($sourceId === '') {
            throw new \InvalidArgumentException('starter content-type contribution has an empty sourceId');
        }
        $slug = trim($definition->slug);
        if ($slug === '') {
            throw new \InvalidArgumentException("starter content-type contribution '{$sourceId}' has an empty slug");
        }
        $name = trim($definition->name);
        if ($name === '') {
            throw new \InvalidArgumentException("starter content-type contribution '{$sourceId}' has an empty name");
        }
        if ($definition->cacheTtl !== null && $definition->cacheTtl < 0) {
            throw new \InvalidArgumentException(
                "starter content-type contribution '{$sourceId}' has a negative cacheTtl"
            );
        }

        try {
            $schema = ContentTypeSchema::fromArray($definition->schema)->toArray();
        } catch (SchemaParseException $e) {
            throw new SchemaParseException(
                "starter content-type contribution '{$sourceId}' has an invalid schema: " . $e->getMessage(),
                previous: $e,
            );
        }

        return new StarterDefinition(
            sourceId: $definition->sourceId,
            definitionKey: $slug,
            payload: [
                'slug' => $slug,
                'name' => $name,
                'description' => $definition->description,
                'cache_ttl' => $definition->cacheTtl,
                'public_delivery' => $definition->publicDelivery,
                'mount_at_root' => $definition->mountAtRoot,
                'schema' => $schema,
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
                    "duplicate starter content-type sourceId '{$definition->sourceId}'"
                );
            }
            $seenSourceIds[$definition->sourceId] = true;

            if (isset($seenSlugs[$definition->definitionKey])) {
                throw new \InvalidArgumentException(
                    "duplicate starter content-type slug '{$definition->definitionKey}'"
                );
            }
            $seenSlugs[$definition->definitionKey] = true;
        }
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function normalizeRow(array $row): array
    {
        return [
            'name' => (string) $row['name'],
            'description' => $row['description'] === null ? null : (string) $row['description'],
            'cache_ttl' => $row['cache_ttl'],
            'public_delivery' => (bool) $row['public_delivery'],
            'mount_at_root' => (bool) $row['mount_at_root'],
            'schema' => (array) $row['schema'],
        ];
    }
}

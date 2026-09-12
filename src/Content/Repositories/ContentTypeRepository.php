<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Repositories;

use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Content\Schema\SchemaParseException;
use Glueful\Database\Connection;
use Glueful\Helpers\Utils;

final class ContentTypeRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): string
    {
        $uuid = Utils::generateNanoID(12);
        $schema = ContentTypeSchema::fromArray((array) ($data['schema'] ?? []));
        $this->db->table('content_types')->insert([
            'uuid' => $uuid,
            'slug' => (string) $data['slug'],
            'name' => (string) $data['name'],
            'description' => isset($data['description']) ? (string) $data['description'] : null,
            'cache_ttl' => isset($data['cache_ttl']) ? max(0, (int) $data['cache_ttl']) : null,
            'public_delivery' => (bool) ($data['public_delivery'] ?? false),
            'mount_at_root' => (bool) ($data['mount_at_root'] ?? false),
            'status' => 'active',
            'schema' => json_encode($schema->toArray(), JSON_THROW_ON_ERROR),
            'schema_version' => 1,
            'created_by' => isset($data['created_by']) ? (string) $data['created_by'] : null,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);
        return $uuid;
    }

    /** @param list<array<string,mixed>> $schema */
    public function updateSchema(string $uuid, array $schema): void
    {
        $parsed = ContentTypeSchema::fromArray($schema);
        $current = $this->findByUuid($uuid);
        if ($current === null) {
            throw new SchemaParseException("content type {$uuid} not found");
        }
        // V1 content models are field-append-only: deleting or retyping a field is rejected
        // here (renames surface as a delete + add). Migrating existing draft/published content
        // across such a change is a backfill feature planned for V1.x/V2; until then the safe
        // workaround is to add a new field and leave the old one in place. See V1_DESIGN §1.
        $destructive = $this->destructiveChanges((array) $current['schema'], $parsed->toArray());
        if ($destructive !== []) {
            throw new SchemaParseException(
                'destructive schema changes (delete/retype a field) are not supported in V1 — '
                . 'add a new field instead; migrating existing content is planned for a later '
                . 'release. Offending field(s): ' . implode(', ', $destructive)
            );
        }
        $this->db->table('content_types')->where('uuid', '=', $uuid)->update([
            'schema' => json_encode($parsed->toArray(), JSON_THROW_ON_ERROR),
            'schema_version' => (int) $current['schema_version'] + 1,
            'updated_at' => $this->now(),
        ]);
    }

    /**
     * Update NON-SCHEMA metadata (content-type PATCH): only supplied keys
     * change; the slug is immutable and schema edits have their own path.
     *
     * @param array<string,mixed> $partial
     */
    public function updateMeta(string $uuid, array $partial): void
    {
        $update = [];
        if (array_key_exists('name', $partial) && $partial['name'] !== null) {
            $update['name'] = trim((string) $partial['name']);
        }
        if (array_key_exists('description', $partial) && $partial['description'] !== null) {
            $update['description'] = (string) $partial['description'];
        }
        if (array_key_exists('cache_ttl', $partial) && $partial['cache_ttl'] !== null) {
            $update['cache_ttl'] = max(0, (int) $partial['cache_ttl']);
        }
        if (array_key_exists('public_delivery', $partial) && $partial['public_delivery'] !== null) {
            $update['public_delivery'] = (bool) $partial['public_delivery'];
        }
        if (array_key_exists('mount_at_root', $partial) && $partial['mount_at_root'] !== null) {
            $update['mount_at_root'] = (bool) $partial['mount_at_root'];
        }
        if ($update === []) {
            return;
        }
        $update['updated_at'] = $this->now();
        $this->db->table('content_types')->where('uuid', '=', $uuid)->update($update);
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        return $this->hydrate($this->db->table('content_types')
            ->where('uuid', '=', $uuid)
            ->where('status', '!=', 'deleted')
            ->first());
    }

    /** @return array<string,mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        return $this->hydrate($this->db->table('content_types')
            ->where('slug', '=', $slug)
            ->where('status', '!=', 'deleted')
            ->first());
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return array_map(
            fn(array $r): array => (array) $this->hydrate($r),
            $this->db->table('content_types')
                ->where('status', '!=', 'deleted')
                ->orderBy('slug', 'ASC')
                ->get()
        );
    }

    public function softDelete(string $uuid): void
    {
        $this->db->table('content_types')->where('uuid', '=', $uuid)->update([
            'status' => 'deleted',
            'updated_at' => $this->now(),
        ]);
    }

    public function schemaFor(string $uuid): ContentTypeSchema
    {
        $row = $this->findByUuid($uuid);
        if ($row === null) {
            throw new \RuntimeException("content type {$uuid} not found");
        }
        return ContentTypeSchema::fromArray($row['schema']);
    }

    /** @param array<string,mixed>|null $row */
    private function hydrate(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $row['schema'] = is_string($row['schema'] ?? null)
            ? (json_decode((string) $row['schema'], true) ?? [])
            : (array) ($row['schema'] ?? []);
        $row['schema_version'] = (int) $row['schema_version'];
        $row['cache_ttl'] = isset($row['cache_ttl']) ? (int) $row['cache_ttl'] : null;
        $row['public_delivery'] = (bool) ($row['public_delivery'] ?? false);
        $row['mount_at_root'] = (bool) ($row['mount_at_root'] ?? false);
        return $row;
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    /**
     * @param list<array<string,mixed>> $old
     * @param list<array<string,mixed>> $new
     * @return list<string>
     */
    private function destructiveChanges(array $old, array $new): array
    {
        $oldByName = [];
        foreach ($old as $field) {
            if (isset($field['name']) && is_string($field['name'])) {
                $oldByName[$field['name']] = $field;
            }
        }
        $newByName = [];
        foreach ($new as $field) {
            if (isset($field['name']) && is_string($field['name'])) {
                $newByName[$field['name']] = $field;
            }
        }

        $changes = [];
        foreach ($oldByName as $name => $field) {
            if (!isset($newByName[$name])) {
                $changes[] = "deleted field {$name}";
                continue;
            }
            if (($field['type'] ?? null) !== ($newByName[$name]['type'] ?? null)) {
                $changes[] = "retyped field {$name}";
            }
        }
        return $changes;
    }
}

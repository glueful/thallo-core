<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Starter;

use Glueful\Database\Connection;
use Glueful\Helpers\Utils;

final class StarterProvenanceRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return array<string,mixed>|null */
    public function findBySource(string $kind, string $sourceId): ?array
    {
        return $this->row(
            $this->db->table('starter_provenance')
                ->where('definition_kind', '=', $kind)
                ->where('source_id', '=', $sourceId)
                ->first()
        );
    }

    /** @return array<string,mixed>|null */
    public function findByKey(string $kind, string $definitionKey): ?array
    {
        return $this->row(
            $this->db->table('starter_provenance')
                ->where('definition_kind', '=', $kind)
                ->where('definition_key', '=', $definitionKey)
                ->first()
        );
    }

    public function recordApplied(
        string $kind,
        string $definitionKey,
        string $sourceId,
        string $fingerprint,
    ): void {
        $now = gmdate('Y-m-d H:i:s');
        $existing = $this->findBySource($kind, $sourceId);
        if ($existing === null) {
            $this->db->table('starter_provenance')->insert([
                'uuid' => Utils::generateNanoID(12),
                'definition_kind' => $kind,
                'definition_key' => $definitionKey,
                'source_id' => $sourceId,
                'fingerprint' => $fingerprint,
                'state' => 'applied',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            return;
        }

        $this->db->table('starter_provenance')
            ->where('uuid', '=', (string) $existing['uuid'])
            ->update([
                'definition_key' => $definitionKey,
                'fingerprint' => $fingerprint,
                'state' => 'applied',
                'updated_at' => $now,
            ]);
    }

    /**
     * HARD delete — a starter definition's provenance record no longer applies (physical
     * retirement of the definition itself), not a soft state transition. No-op if absent.
     */
    public function deleteBySource(string $kind, string $sourceId): void
    {
        $this->db->table('starter_provenance')
            ->where('definition_kind', '=', $kind)
            ->where('source_id', '=', $sourceId)
            ->delete();
    }

    public function markState(string $uuid, string $state): void
    {
        if (!in_array($state, ['applied', 'customized', 'orphaned_source'], true)) {
            throw new \InvalidArgumentException("Unknown starter provenance state: {$state}");
        }
        $this->db->table('starter_provenance')->where('uuid', '=', $uuid)->update([
            'state' => $state,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    public function renameKey(string $uuid, string $newKey): void
    {
        $this->db->table('starter_provenance')->where('uuid', '=', $uuid)->update([
            'definition_key' => $newKey,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    /** @return list<array<string,mixed>> */
    public function sourceIdsFor(string $kind): array
    {
        return array_map(
            static fn(array $row): array => (array) $row,
            $this->db->table('starter_provenance')
                ->where('definition_kind', '=', $kind)
                ->orderBy('source_id', 'ASC')
                ->get(),
        );
    }

    /** @return list<array<string,mixed>> */
    public function allFor(string $kind): array
    {
        return $this->sourceIdsFor($kind);
    }

    /** @return list<array{definition_kind:string,definition_key:string,state:string}> */
    public function divergentStates(): array
    {
        $rows = $this->db->table('starter_provenance')
            ->whereIn('state', ['customized', 'orphaned_source'])
            ->orderBy('definition_kind', 'ASC')
            ->orderBy('definition_key', 'ASC')
            ->get();
        return array_map(static fn(array $row): array => [
            'definition_kind' => (string) $row['definition_kind'],
            'definition_key' => (string) $row['definition_key'],
            'state' => (string) $row['state'],
        ], $rows);
    }

    /** @return array<string,mixed>|null */
    private function row(mixed $row): ?array
    {
        return $row === null ? null : (array) $row;
    }
}

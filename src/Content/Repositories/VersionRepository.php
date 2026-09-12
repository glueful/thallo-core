<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Repositories;

use Glueful\Database\Connection;
use Glueful\Helpers\Utils;

final class VersionRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function nextVersionNumber(string $entryUuid, string $locale): int
    {
        $max = $this->db->table('entry_versions')
            ->where('entry_uuid', '=', $entryUuid)->where('locale', '=', $locale)
            ->max('version');
        return (int) ($max ?? 0) + 1;
    }

    public function reserveNextVersionNumber(string $entryUuid, string $locale): int
    {
        $lockKey = "thallo:entry_versions:{$entryUuid}:{$locale}";
        $stmt = $this->db->getPDO()->prepare('SELECT pg_advisory_xact_lock(hashtextextended(:lock_key, 0))');
        $stmt->execute(['lock_key' => $lockKey]);

        return $this->nextVersionNumber($entryUuid, $locale);
    }

    /** @param array<string,mixed> $fields  Returns the new version uuid. */
    public function appendVersion(
        string $entryUuid,
        string $locale,
        int $version,
        array $fields,
        int $schemaVersion,
        ?string $actor,
    ): string {
        $uuid = Utils::generateNanoID(12);
        $this->db->table('entry_versions')->insert([
            'uuid' => $uuid,
            'entry_uuid' => $entryUuid,
            'locale' => $locale,
            'version' => $version,
            'fields' => json_encode($fields, JSON_THROW_ON_ERROR),
            'schema_version' => $schemaVersion,
            'created_by' => $actor,
            // MICROSECONDS (block-migrations spec §5): the restore suffix compares
            // this against block_type_migrations.created_at with strict > —
            // second precision would make same-second version/migration pairs
            // ambiguous. Postgres timestamp columns store µs natively.
            'created_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
        ]);
        return $uuid;
    }

    public function pin(string $entryUuid, string $locale, string $versionUuid, ?string $actor): void
    {
        $existing = $this->db->table('entry_publications')
            ->where('entry_uuid', '=', $entryUuid)->where('locale', '=', $locale)->first();
        $data = [
            'version_uuid' => $versionUuid,
            'published_by' => $actor,
            'published_at' => (new \DateTimeImmutable())->format('Y-m-d H:i:s.u'),
        ];
        if ($existing === null) {
            $this->db->table('entry_publications')->insert(array_merge(
                ['entry_uuid' => $entryUuid, 'locale' => $locale],
                $data
            ));
        } else {
            $this->db->table('entry_publications')
                ->where('entry_uuid', '=', $entryUuid)->where('locale', '=', $locale)->update($data);
        }
    }

    public function unpin(string $entryUuid, string $locale): void
    {
        $this->db->table('entry_publications')
            ->where('entry_uuid', '=', $entryUuid)->where('locale', '=', $locale)->delete();
    }

    /** @return array<string,mixed>|null */
    public function findPublication(string $entryUuid, string $locale): ?array
    {
        return $this->db->table('entry_publications')
            ->where('entry_uuid', '=', $entryUuid)->where('locale', '=', $locale)->first() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findVersionByUuid(string $uuid): ?array
    {
        $row = $this->db->table('entry_versions')->where('uuid', '=', $uuid)->first();
        if ($row === null) {
            return null;
        }
        $row['fields'] = is_string($row['fields'] ?? null)
            ? (json_decode((string) $row['fields'], true) ?? [])
            : (array) ($row['fields'] ?? []);
        $row['version'] = (int) $row['version'];
        return $row;
    }

    /** @return list<array<string,mixed>> newest first */
    public function versionsFor(string $entryUuid, string $locale): array
    {
        return $this->db->table('entry_versions')
            ->where('entry_uuid', '=', $entryUuid)->where('locale', '=', $locale)
            ->orderBy('version', 'DESC')->get();
    }
}

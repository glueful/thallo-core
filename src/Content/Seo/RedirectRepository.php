<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Seo;

use Glueful\Database\Connection;
use Glueful\Helpers\Utils;

final class RedirectRepository
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): string
    {
        $uuid = Utils::generateNanoID(12);
        $this->db->table('entry_redirects')->insert([
            'uuid' => $uuid,
            'content_type_uuid' => (string) $data['content_type_uuid'],
            'locale' => (string) $data['locale'],
            'source_slug' => (string) $data['source_slug'],
            'target_content_type_uuid' => isset($data['target_content_type_uuid'])
                ? (string) $data['target_content_type_uuid']
                : null,
            'target_locale' => isset($data['target_locale']) ? (string) $data['target_locale'] : null,
            'target_entry_uuid' => isset($data['target_entry_uuid']) ? (string) $data['target_entry_uuid'] : null,
            'target_url' => isset($data['target_url']) ? (string) $data['target_url'] : null,
            'status' => (int) ($data['status'] ?? 301),
            'origin' => (string) ($data['origin'] ?? 'manual'),
            'created_by' => isset($data['created_by']) ? (string) $data['created_by'] : null,
            'created_at' => $this->now(),
            'updated_at' => $this->now(),
        ]);

        return $uuid;
    }

    public function upsertAuto(
        string $contentTypeUuid,
        string $locale,
        string $sourceSlug,
        string $targetContentTypeUuid,
        string $targetLocale,
        string $targetEntryUuid
    ): string {
        $existing = $this->findBySource($contentTypeUuid, $locale, $sourceSlug);
        if ($existing === null) {
            return $this->create([
                'content_type_uuid' => $contentTypeUuid,
                'locale' => $locale,
                'source_slug' => $sourceSlug,
                'target_content_type_uuid' => $targetContentTypeUuid,
                'target_locale' => $targetLocale,
                'target_entry_uuid' => $targetEntryUuid,
                'status' => 301,
                'origin' => 'auto',
            ]);
        }

        $this->db->table('entry_redirects')->where('uuid', '=', (string) $existing['uuid'])->update([
            'target_content_type_uuid' => $targetContentTypeUuid,
            'target_locale' => $targetLocale,
            'target_entry_uuid' => $targetEntryUuid,
            'target_url' => null,
            'status' => 301,
            'origin' => 'auto',
            'updated_at' => $this->now(),
        ]);

        return (string) $existing['uuid'];
    }

    /** @return array<string,mixed>|null */
    public function findByUuid(string $uuid): ?array
    {
        return $this->normalize($this->db->table('entry_redirects')->where('uuid', '=', $uuid)->first());
    }

    /** @return array<string,mixed>|null */
    public function findBySource(string $contentTypeUuid, string $locale, string $sourceSlug): ?array
    {
        return $this->normalize(
            $this->db->table('entry_redirects')
                ->where('content_type_uuid', '=', $contentTypeUuid)
                ->where('locale', '=', $locale)
                ->where('source_slug', '=', $sourceSlug)
                ->first()
        );
    }

    /**
     * Redirect lookup across ROOT-MOUNTED types (root-mounted-types spec §4):
     * the root 1-segment grammar consults renames from every root type after
     * a route miss. Deterministic by construction — the RootMountGuard keeps
     * root redirect sources globally unique per locale.
     *
     * @return array<string,mixed>|null
     */
    public function findBySourceAcrossRootTypes(string $locale, string $sourceSlug): ?array
    {
        return $this->normalize(
            $this->db->table('entry_redirects')
                ->select(['entry_redirects.*'])
                ->join('content_types', 'content_types.uuid', '=', 'entry_redirects.content_type_uuid')
                ->where('content_types.mount_at_root', '=', true)
                ->where('content_types.status', '!=', 'deleted')
                ->where('entry_redirects.locale', '=', $locale)
                ->where('entry_redirects.source_slug', '=', $sourceSlug)
                ->first()
        );
    }

    /** @return list<array<string,mixed>> */
    public function listForType(string $contentTypeUuid, ?string $locale = null): array
    {
        $query = $this->db->table('entry_redirects')
            ->where('content_type_uuid', '=', $contentTypeUuid)
            ->orderBy('locale', 'ASC')
            ->orderBy('source_slug', 'ASC');
        if ($locale !== null) {
            $query->where('locale', '=', $locale);
        }

        return array_map(fn (array $row): array => (array) $this->normalize($row), $query->get());
    }

    public function deleteBySource(string $contentTypeUuid, string $locale, string $sourceSlug): void
    {
        $this->db->table('entry_redirects')
            ->where('content_type_uuid', '=', $contentTypeUuid)
            ->where('locale', '=', $locale)
            ->where('source_slug', '=', $sourceSlug)
            ->delete();
    }

    public function deleteByUuid(string $uuid, ?string $contentTypeUuid = null): bool
    {
        $query = $this->db->table('entry_redirects')->where('uuid', '=', $uuid);
        if ($contentTypeUuid !== null) {
            $query->where('content_type_uuid', '=', $contentTypeUuid);
        }

        return $query->delete() > 0;
    }

    /** @param array<string,mixed>|null $row */
    private function normalize(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }

        $row['status'] = (int) $row['status'];
        return $row;
    }

    private function now(): string
    {
        return date('Y-m-d H:i:s');
    }
}

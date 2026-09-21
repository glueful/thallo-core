<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Authoring;

use Glueful\Database\Connection;
use Thallo\Contracts\Authoring\ContentUpserter;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Repositories\RouteRepository;
use Thallo\Core\Content\Routing\RootMountGuard;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Content\Validation\FieldValidator;

/**
 * {@see ContentUpserter} over the engine's own repositories — the same validation, the same
 * optimistic lock and the same route rules as the admin's entry endpoints.
 */
final class EngineContentUpserter implements ContentUpserter
{
    public function __construct(
        private readonly Connection $db,
        private readonly EntryRepository $entries,
        private readonly ContentTypeRepository $types,
        private readonly RouteRepository $routes,
        private readonly FieldValidator $validator,
        private readonly ?RootMountGuard $rootGuard = null,
    ) {
    }

    public function findBySlug(string $contentTypeUuid, string $locale, string $slug): ?string
    {
        $route = $this->routes->findBySlug($contentTypeUuid, $locale, $slug);
        return $route === null ? null : (string) $route['entry_uuid'];
    }

    public function findByField(string $contentTypeUuid, string $locale, string $field, string $value): ?string
    {
        if (!$this->isField($contentTypeUuid, $field)) {
            return null;
        }
        $row = $this->db->table('entry_drafts as d')
            ->join('entries as e', 'e.uuid', '=', 'd.entry_uuid')
            ->select(['d.entry_uuid'])
            ->where('e.content_type_uuid', '=', $contentTypeUuid)
            ->where('e.status', '=', 'active')
            ->where('d.locale', '=', $locale)
            ->whereRaw('d.fields::jsonb ->> ? = ?', [$field, $value])
            ->orderBy('d.id', 'ASC')
            ->first();
        return $row === null ? null : (string) $row['entry_uuid'];
    }

    public function fieldValues(string $contentTypeUuid, string $locale, string $field): array
    {
        if (!$this->isField($contentTypeUuid, $field)) {
            return [];
        }
        $rows = $this->db->table('entry_drafts as d')
            ->join('entries as e', 'e.uuid', '=', 'd.entry_uuid')
            ->select(['d.entry_uuid', 'd.fields'])
            ->where('e.content_type_uuid', '=', $contentTypeUuid)
            ->where('e.status', '=', 'active')
            ->where('d.locale', '=', $locale)
            ->get();
        $out = [];
        foreach ($rows as $row) {
            $fields = is_string($row['fields'] ?? null)
                ? (array) (json_decode((string) $row['fields'], true) ?? [])
                : (array) ($row['fields'] ?? []);
            if (is_string($fields[$field] ?? null) && $fields[$field] !== '') {
                $out[(string) $row['entry_uuid']] = $fields[$field];
            }
        }
        return $out;
    }

    /** A field name reaches SQL as a JSON key, so it is only ever one the type's schema names. */
    private function isField(string $contentTypeUuid, string $field): bool
    {
        $type = $this->types->findByUuid($contentTypeUuid);
        if ($type === null) {
            return false;
        }
        foreach (ContentTypeSchema::fromArray($type['schema'])->fields() as $f) {
            if ($f->name === $field) {
                return true;
            }
        }
        return false;
    }

    public function current(string $entryUuid, string $locale): ?array
    {
        $draft = $this->entries->findDraft($entryUuid, $locale);
        if ($draft === null) {
            return null;
        }
        $slug = null;
        $published = false;
        foreach ($this->entries->localeSummary($entryUuid) as $summary) {
            if ((string) $summary['locale'] === $locale) {
                $slug = is_string($summary['route_slug'] ?? null) ? $summary['route_slug'] : null;
                $published = (bool) ($summary['is_published'] ?? false);
            }
        }
        return ['fields' => (array) $draft['fields'], 'slug' => $slug, 'published' => $published];
    }

    public function updateDraft(string $entryUuid, string $locale, array $fields, ?string $actor = null): void
    {
        $entry = $this->entries->findEntry($entryUuid);
        $draft = $this->entries->findDraft($entryUuid, $locale);
        $type = $entry === null ? null : $this->types->findByUuid((string) $entry['content_type_uuid']);
        if ($draft === null || $type === null) {
            throw new \RuntimeException("entry {$entryUuid} has no draft in {$locale}");
        }
        $clean = $this->validator->validate(ContentTypeSchema::fromArray($type['schema']), $fields);
        $this->entries->saveDraft(
            $entryUuid,
            $locale,
            $clean,
            (int) $type['schema_version'],
            (int) $draft['lock_version'],
            $actor,
        );
    }

    public function assignSlug(string $entryUuid, string $contentTypeUuid, string $locale, string $slug): void
    {
        $existing = $this->routes->findBySlug($contentTypeUuid, $locale, $slug);
        if ($existing !== null && (string) $existing['entry_uuid'] !== $entryUuid) {
            throw new \RuntimeException("The URL \"{$slug}\" is already another entry's.");
        }
        $type = $this->types->findByUuid($contentTypeUuid);
        if (($type['mount_at_root'] ?? false) === true && $this->rootGuard !== null) {
            $conflicts = $this->rootGuard->conflictsForSlug($locale, $slug, $entryUuid);
            if ($conflicts !== []) {
                throw new \RuntimeException("The URL \"{$slug}\" is unavailable: " . implode('; ', $conflicts));
            }
        }
        $this->routes->assign($entryUuid, $contentTypeUuid, $locale, $slug);
    }
}

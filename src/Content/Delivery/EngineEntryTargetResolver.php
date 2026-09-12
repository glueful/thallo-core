<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Delivery;

use Thallo\Core\Content\Seo\CanonicalPathBuilder;
use Glueful\Database\Connection;
use Thallo\Contracts\Delivery\EntryTargetResolver;

/** Engine-backed EntryTargetResolver over entries/publications/routes/content_types. */
final class EngineEntryTargetResolver implements EntryTargetResolver
{
    public function __construct(
        private readonly Connection $db,
        private readonly CanonicalPathBuilder $canonical,
    ) {
    }

    public function resolve(string $entryUuid, string $locale): array
    {
        $entry = $this->db->table('entries')->select(['content_type_uuid', 'status'])
            ->where('uuid', '=', $entryUuid)->first();
        if ($entry === null) {
            return ['status' => 'missing', 'path' => null, 'title' => null];
        }
        if (($entry['status'] ?? null) === 'deleted') {
            return ['status' => 'deleted', 'path' => null, 'title' => null];
        }

        $publication = $this->db->table('entry_publications')
            ->where('entry_uuid', '=', $entryUuid)->where('locale', '=', $locale)->first();
        if ($publication === null) {
            return ['status' => 'unpublished', 'path' => null, 'title' => $this->draftTitle($entryUuid, $locale)];
        }
        $route = $this->db->table('entry_routes')->select(['slug'])
            ->where('entry_uuid', '=', $entryUuid)->where('locale', '=', $locale)->first();
        // Published-but-routeless: live content that cannot be linked until a route is
        // assigned. Distinct status so the menu editor can say "assign a route" rather
        // than "publish this"; path stays null so no consumer renders a dead link.
        if ($route === null) {
            return [
                'status' => 'routeless',
                'path' => null,
                'title' => $this->publishedTitle($publication) ?? $this->draftTitle($entryUuid, $locale),
            ];
        }

        $type = $this->db->table('content_types')->select(['slug', 'mount_at_root'])
            ->where('uuid', '=', (string) $entry['content_type_uuid'])->first();
        // Menus render this path verbatim, so it must be the CANONICAL form:
        // default locale collapsed, root-mounted types at /{slug}.
        $path = $this->canonical->pathFor(
            (string) ($type['slug'] ?? ''),
            (bool) ($type['mount_at_root'] ?? false),
            $locale,
            (string) $route['slug'],
        );
        return ['status' => 'published', 'path' => $path, 'title' => $this->publishedTitle($publication)];
    }

    /**
     * The PUBLISHED version's `title` field — what a menu label inheriting the
     * page title will actually render (nav-entry-items design).
     *
     * @param array<string,mixed> $publication
     */
    private function publishedTitle(array $publication): ?string
    {
        $version = $this->db->table('entry_versions')->select(['fields'])
            ->where('uuid', '=', (string) ($publication['version_uuid'] ?? ''))->first();
        return $this->titleFrom($version['fields'] ?? null);
    }

    /** The draft's `title` — the editor-facing preview for unpublished targets. */
    private function draftTitle(string $entryUuid, string $locale): ?string
    {
        $draft = $this->db->table('entry_drafts')->select(['fields'])
            ->where('entry_uuid', '=', $entryUuid)->where('locale', '=', $locale)->first();
        return $this->titleFrom($draft['fields'] ?? null);
    }

    private function titleFrom(mixed $fields): ?string
    {
        if (is_string($fields)) {
            $fields = json_decode($fields, true);
        }
        $title = is_array($fields) ? ($fields['title'] ?? null) : null;
        return is_string($title) && trim($title) !== '' ? $title : null;
    }
}

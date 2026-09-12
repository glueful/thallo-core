<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Authoring;

use Glueful\Database\Connection;
use Thallo\Contracts\Authoring\DraftSummaryReader;

/** Engine-backed DraftSummaryReader over entries/entry_drafts/content_types. */
final class EngineDraftSummaryReader implements DraftSummaryReader
{
    public function __construct(private readonly Connection $db)
    {
    }

    public function summary(string $entryUuid, string $locale): ?array
    {
        $draft = $this->db->table('entry_drafts')->select(['fields'])
            ->where('entry_uuid', '=', $entryUuid)
            ->where('locale', '=', $locale)
            ->first();
        if ($draft === null) {
            return null;
        }
        $entry = $this->db->table('entries')->select(['content_type_uuid', 'status'])
            ->where('uuid', '=', $entryUuid)
            ->first();
        if ($entry === null || ($entry['status'] ?? null) === 'deleted') {
            return null;
        }
        $typeUuid = (string) $entry['content_type_uuid'];
        $type = $this->db->table('content_types')->select(['slug'])
            ->where('uuid', '=', $typeUuid)
            ->first();

        $fields = json_decode((string) $draft['fields'], true);
        $title = is_array($fields) && is_string($fields['title'] ?? null) ? $fields['title'] : null;

        return [
            'entry_uuid' => $entryUuid,
            'locale' => $locale,
            'title' => $title,
            'type_uuid' => $typeUuid,
            'type_slug' => (string) ($type['slug'] ?? ''),
        ];
    }
}

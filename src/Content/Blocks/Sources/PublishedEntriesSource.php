<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Blocks\Sources;

use Glueful\Database\Connection;
use Thallo\Core\Content\Repositories\ReferenceProjectionRepository;
use Thallo\Core\Content\Repositories\VersionRepository;

/**
 * The published version of every non-deleted entry whose type carries blocks, persisted by
 * append-and-repin: a rewrite becomes a NEW version pinned as the publication, the previous
 * version stays what it was (the restore projection replays migrations per era). This is the
 * source a block-type migration writes published content through; the settings converter,
 * which must leave no document behind, rewrites every retained version through
 * {@see EntryVersionsSource} instead.
 */
final class PublishedEntriesSource implements BlockDocumentSource
{
    public const ID = 'entry_published';

    public function __construct(
        private readonly Connection $db,
        private readonly BlockContentTypes $types,
        private readonly VersionRepository $versions,
        private readonly ReferenceProjectionRepository $references,
    ) {
    }

    public function id(): string
    {
        return self::ID;
    }

    public function each(callable $fn): void
    {
        foreach ($this->types->all() as $type) {
            $rows = $this->db->table('entry_publications as p')
                ->join('entries as e', 'e.uuid', '=', 'p.entry_uuid')
                ->select(['p.entry_uuid', 'p.locale', 'p.version_uuid'])
                ->where('e.content_type_uuid', '=', $type['uuid'])
                ->where('e.status', '!=', 'deleted')
                ->get();
            foreach ($rows as $row) {
                $version = $this->versions->findVersionByUuid((string) $row['version_uuid']);
                if ($version === null) {
                    continue;
                }
                $fn(new DocumentRef(
                    self::ID,
                    (string) $row['entry_uuid'],
                    (string) $row['locale'],
                    (string) $row['version_uuid'],
                    $type['schema'],
                    (array) $version['fields'],
                    [
                        'content_type' => $type['slug'],
                        'entry_uuid' => (string) $row['entry_uuid'],
                        'schema_version' => $type['schema_version'],
                    ],
                ));
            }
        }
    }

    public function persist(DocumentRef $ref, array $fields, ?string $actor = null): bool
    {
        $written = false;
        $this->db->transaction(function () use ($ref, $fields, $actor, &$written): void {
            $entry = $ref->sourceId;
            $locale = (string) $ref->locale;
            $number = $this->versions->reserveNextVersionNumber($entry, $locale);
            $current = $this->versions->findPublication($entry, $locale);
            if ($current === null || (string) $current['version_uuid'] !== $ref->revision) {
                return; // moved on: another publication landed since the read
            }
            $newUuid = $this->versions->appendVersion(
                $entry,
                $locale,
                $number,
                $fields,
                (int) ($ref->meta['schema_version'] ?? 1),
                $actor,
            );
            $this->versions->pin($entry, $locale, $newUuid, $actor);
            $this->references->rebuildForEntry($entry, $ref->schema, $fields, $locale);
            $written = true;
        });
        return $written;
    }
}

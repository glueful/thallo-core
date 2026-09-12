<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Services;

use Thallo\Core\Content\Blocks\BlockMigrationGate;
use Thallo\Core\Content\Blocks\BlockRestoreProjector;
use Thallo\Core\Content\Events\EntryPublished;
use Thallo\Core\Content\Events\EntryUnpublished;
use Thallo\Core\Content\Pipeline\PublishEventEmitter;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Repositories\ReferenceProjectionRepository;
use Thallo\Core\Content\Repositories\VersionRepository;
use Thallo\Core\Content\Schema\Migration\SchemaProjector;
use Thallo\Core\Content\Validation\FieldValidator;
use Glueful\Bootstrap\ApplicationContext;
use Thallo\Contracts\Authoring\PublishGate;

final class PublishService
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly EntryRepository $entries,
        private readonly VersionRepository $versions,
        private readonly ContentTypeRepository $types,
        private readonly FieldValidator $validator,
        private readonly ReferenceProjectionRepository $references,
        private readonly ?PublishEventEmitter $events = null,
        private readonly ?SchemaProjector $projector = null,
        /** @var list<PublishGate> Tag-discovered (`thallo.publish_gate`); empty = ungated. */
        private readonly array $publishGates = [],
        /** Block-migration write gate (spec §3); null = ungated (tests, minimal wiring). */
        private readonly ?BlockMigrationGate $blockGate = null,
        /** Restore projection (spec §5); null = plain re-pin rollbacks (tests, minimal wiring). */
        private readonly ?BlockRestoreProjector $blockRestore = null,
    ) {
    }

    /**
     * Validate the current draft, snapshot it as the next immutable version, and pin it —
     * all in one transaction (V1_DESIGN §2/§5). Returns the new version uuid.
     */
    public function publish(string $entryUuid, string $locale, ?string $actor): string
    {
        return $this->publishInternal($entryUuid, $locale, $actor, true);
    }

    /** Publish code-owned starter content while retaining validation and projection. */
    public function publishStarter(string $entryUuid, string $locale, ?string $actor): string
    {
        return $this->publishInternal($entryUuid, $locale, $actor, false);
    }

    private function publishInternal(
        string $entryUuid,
        string $locale,
        ?string $actor,
        bool $enforceEditorialGates,
    ): string {
        $entry = $this->entries->findEntry($entryUuid);
        if ($entry === null || ($entry['status'] ?? null) === 'deleted') {
            // A soft-deleted entry keeps its draft/version rows, so publishing would mint a new
            // immutable version and re-pin content that is supposed to be gone. Guard here so both
            // the HTTP path and any other caller share the check (ScheduleRunner already guards).
            // The RuntimeException maps to a 404 in PublicationController.
            throw new \RuntimeException("entry {$entryUuid} not found");
        }
        $draft = $this->entries->findDraft($entryUuid, $locale);
        if ($draft === null) {
            throw new \RuntimeException("no draft for {$entryUuid}/{$locale}");
        }

        // Ask every registered publish gate (workflow pack etc.) BEFORE any write — after the
        // existence checks so 404s beat 409s. The first PublishBlocked stops the publish;
        // unexpected exceptions bubble (a broken gate must not silently allow publishes).
        // No gates → exactly the pre-seam behaviour.
        if ($enforceEditorialGates) {
            foreach ($this->publishGates as $gate) {
                $gate->assertCanPublish($entryUuid, $locale, $actor);
            }
        }

        $typeUuid = (string) $entry['content_type_uuid'];
        $schema = $this->types->schemaFor($typeUuid);

        // Block-migration write gate (spec §3): publish snapshots the stored draft —
        // publishing an un-backfilled draft under a flipped block schema would strip
        // the old keys. The backfill's own republish bypasses PublishService, so
        // this can never deadlock a migration's convergence.
        $this->blockGate?->assertWritable((array) $draft['fields'], $schema);

        // Project a draft still on an OLDER schema up to the current shape before validating, so a
        // draft behind a lagging/failed backfill (e.g. a renamed field) doesn't silently lose the
        // renamed data — FieldValidator only keeps keys the current schema declares. A draft already
        // at the current version is untouched (no projection, same stored version) → behaviour
        // unchanged for the normal path. The snapshot then records the CURRENT version, so delivery
        // read-projection stays a no-op instead of double-projecting.
        $fields = $draft['fields'];
        $storeVersion = (int) $draft['schema_version'];
        if ($this->projector !== null) {
            $typeRow = $this->types->findByUuid($typeUuid);
            $currentVersion = $typeRow === null ? $storeVersion : (int) $typeRow['schema_version'];
            if ($storeVersion < $currentVersion) {
                $fields = $this->projector->project($typeUuid, $storeVersion, $fields);
                $storeVersion = $currentVersion;
            }
        }

        // Throws ValidationException before any write if the draft is invalid. Publish is the strict
        // gate: unlike draft saves, a present-but-empty required field or a dangling reference is
        // rejected here so invalid content can't go live (draft saves stay permissive).
        $clean = $this->validator->validate($schema, $fields, true);

        $version = 0;
        $versionUuid = db($this->context)->transaction(
            function () use ($entryUuid, $locale, $clean, $storeVersion, $actor, $schema, &$version): string {
                $version = $this->versions->reserveNextVersionNumber($entryUuid, $locale);
                $versionUuid = $this->versions->appendVersion(
                    $entryUuid,
                    $locale,
                    $version,
                    $clean,
                    $storeVersion,
                    $actor,
                );
                $this->versions->pin($entryUuid, $locale, $versionUuid, $actor);
                $this->references->rebuildForEntry($entryUuid, $schema, $clean, $locale);
                return $versionUuid;
            }
        );

        // Primary domain event, dispatched on the OUTERMOST commit only. If publish()
        // owns the outermost transaction the commit already happened, so afterCommit
        // dispatches immediately; if an outer transaction is still active the dispatch
        // is bound to it and fires (or is discarded) with that outer commit/rollback.
        $this->events?->emitAfterCommit(new EntryPublished(
            entry: $entryUuid,
            type: (string) $entry['content_type_uuid'],
            locale: $locale,
            version: $version,
            actor: $actor,
        ));

        return $versionUuid;
    }

    public function unpublish(string $entryUuid, string $locale): void
    {
        $entry = $this->entries->findEntry($entryUuid);
        db($this->context)->transaction(function () use ($entryUuid, $locale): void {
            $this->versions->unpin($entryUuid, $locale);
            $this->references->clearForEntryLocale($entryUuid, $locale);
        });
        $this->events?->emitAfterCommit(new EntryUnpublished(
            entry: $entryUuid,
            type: $entry === null ? '' : (string) $entry['content_type_uuid'],
            locale: $locale,
            actor: null,
        ));
    }

    /**
     * Re-pin an existing (older) version — or, when block-migration projection
     * changes its fields (block-migrations spec §5), MATERIALIZE a new projected
     * version (validated strictly) and pin that: a plain re-pin would reintroduce
     * pre-migration keys into current content. Returns the version ACTUALLY
     * pinned — the requested one on the re-pin path, the appended one on the
     * materialized path. Callers must report THIS, not their input; the emitted
     * EntryPublished carries this version number.
     *
     * @return array{version_uuid: string, version: int}
     */
    public function rollback(string $entryUuid, string $locale, string $versionUuid, ?string $actor): array
    {
        $version = $this->versions->findVersionByUuid($versionUuid);
        if (
            $version === null
            || (string) $version['entry_uuid'] !== $entryUuid
            || (string) $version['locale'] !== $locale
        ) {
            throw new \RuntimeException('version does not belong to this entry/locale');
        }
        $entry = $this->entries->findEntry($entryUuid);
        if ($entry !== null && ($entry['status'] ?? null) === 'deleted') {
            // Same rule as publish(): don't re-pin a version onto a soft-deleted entry. Mapped to a
            // 422 in PublicationController::rollback.
            throw new \RuntimeException('entry has been deleted');
        }
        $schema = $entry === null
            ? null
            : $this->types->schemaFor((string) $entry['content_type_uuid']);

        // Block restore projection (spec §5) — BEFORE any write. Unknown block
        // type (hard-deleted) throws here and blocks the restore entirely; a
        // changed projection is validated strictly (it becomes new published
        // content); a no-op projection keeps today's plain re-pin, unvalidated.
        $projectedFields = null;
        if ($this->blockRestore !== null && $schema !== null) {
            [$candidate, $blockChanged] = $this->blockRestore->project(
                (array) $version['fields'],
                $schema,
                (string) $version['created_at'],
            );
            if ($blockChanged) {
                $projectedFields = $this->validator->validate($schema, $candidate, true);
            }
        }

        $pinnedUuid = $versionUuid;
        $pinnedNumber = isset($version['version']) ? (int) $version['version'] : 0;
        db($this->context)->transaction(function () use (
            $entryUuid,
            $locale,
            $versionUuid,
            $actor,
            $schema,
            $version,
            $entry,
            $projectedFields,
            &$pinnedUuid,
            &$pinnedNumber
        ): void {
            if ($projectedFields !== null && $entry !== null && $schema !== null) {
                // Materialize: append-and-repin, the backfill's shape. The new
                // version records the CURRENT content-type schema_version (the
                // fields also passed the content-type projector below-the-fold via
                // validation against the current schema).
                $typeRow = $this->types->findByUuid((string) $entry['content_type_uuid']);
                $currentSchemaVersion = $typeRow === null
                    ? (int) ($version['schema_version'] ?? 0)
                    : (int) $typeRow['schema_version'];
                $pinnedNumber = $this->versions->reserveNextVersionNumber($entryUuid, $locale);
                $pinnedUuid = $this->versions->appendVersion(
                    $entryUuid,
                    $locale,
                    $pinnedNumber,
                    $projectedFields,
                    $currentSchemaVersion,
                    $actor,
                );
                $this->versions->pin($entryUuid, $locale, $pinnedUuid, $actor);
                $this->references->rebuildForEntry($entryUuid, $schema, $projectedFields, $locale);
                return;
            }

            $this->versions->pin($entryUuid, $locale, $versionUuid, $actor);
            if ($schema !== null) {
                $fields = (array) $version['fields'];
                if ($this->projector !== null && $entry !== null) {
                    $fields = $this->projector->project(
                        (string) $entry['content_type_uuid'],
                        (int) ($version['schema_version'] ?? 0),
                        $fields,
                    );
                }
                $this->references->rebuildForEntry($entryUuid, $schema, $fields, $locale);
            }
        });
        // Re-publishing a prior version is a publish for downstream consumers (V1_DESIGN §5).
        // The event carries the ACTUALLY pinned version (materialized or requested).
        $this->events?->emitAfterCommit(new EntryPublished(
            entry: $entryUuid,
            type: $entry === null ? '' : (string) $entry['content_type_uuid'],
            locale: $locale,
            version: $pinnedNumber,
            actor: $actor,
        ));

        return ['version_uuid' => $pinnedUuid, 'version' => $pinnedNumber];
    }
}

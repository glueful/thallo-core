<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Authoring;

use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Schema\ContentTypeSchema;
use Thallo\Core\Content\Services\PublishService;
use Thallo\Core\Content\Validation\FieldValidator;
use Thallo\Contracts\Authoring\ContentWriter;

/**
 * Adapts the engine's authoring services to the high-level ContentWriter contract.
 * This is the sanctioned content-write path for packs: it ENFORCES core validation
 * (FieldValidator) before persisting, exactly like the HTTP EntryController does, and
 * resolves schema/lock versions internally so packs never see them.
 */
final class EngineContentWriter implements ContentWriter
{
    public function __construct(
        private readonly EntryRepository $entries,
        private readonly PublishService $publisher,
        private readonly ContentTypeRepository $types,
        private readonly FieldValidator $validator,
    ) {
    }

    public function validate(string $contentTypeUuid, string $locale, array $fields): array
    {
        $type = $this->types->findByUuid($contentTypeUuid);
        if ($type === null) {
            throw new \RuntimeException("content type {$contentTypeUuid} not found");
        }
        $schema = ContentTypeSchema::fromArray($type['schema']);

        return $this->validator->validate($schema, $fields);
    }

    public function createDraft(string $contentTypeUuid, string $locale, array $fields, ?string $actor = null): string
    {
        $type = $this->types->findByUuid($contentTypeUuid);
        if ($type === null) {
            throw new \RuntimeException("content type {$contentTypeUuid} not found");
        }
        $schemaVersion = (int) $type['schema_version'];

        // Enforce core validation up front — saveDraft() requires an already-cleaned
        // payload. Throws ValidationException on bad input (same contract as the HTTP path).
        $clean = $this->validate($contentTypeUuid, $locale, $fields);

        // createEntry() also seeds an empty draft at lock_version 0, so saveDraft() with
        // expectedLockVersion 0 CAS-matches and writes the validated fields.
        $entryUuid = $this->entries->createEntry($contentTypeUuid, $locale, $schemaVersion, $actor);
        $this->entries->saveDraft($entryUuid, $locale, $clean, $schemaVersion, 0, $actor);
        return $entryUuid;
    }

    public function publish(string $entryUuid, string $locale, ?string $actor = null): string
    {
        return $this->publisher->publish($entryUuid, $locale, $actor);
    }
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Services;

use Thallo\Core\Content\Blocks\BlockRestoreProjector;
use Thallo\Core\Content\Palette\ColorTokenWalker;
use Thallo\Core\Content\Palette\PaletteNormalizer;
use Thallo\Core\Content\Palette\PaletteOutcome;
use Thallo\Contracts\Style\Palette;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Repositories\VersionRepository;
use Thallo\Core\Content\Validation\FieldValidator;

/**
 * Restore a retained version into the draft, on the server (custom palette spec §4.5; plan Task 12).
 * The version is loaded by its id from the entry's own retained versions and projected; the draft takes
 * every field of it but its own `_schema`. The version's brand colours are this save's trusted basis
 * and are recorded with the draft, so later saves — undo, redo — keep them, even after retention prunes
 * the version. The client names only which version; it never supplies old content.
 */
final class DraftRestore
{
    public function __construct(
        private readonly EntryRepository $entries,
        private readonly VersionRepository $versions,
        private readonly ContentTypeRepository $types,
        private readonly FieldValidator $validator,
        private readonly PaletteNormalizer $normalizer,
        private readonly ?BlockRestoreProjector $blockRestore = null,
    ) {
    }

    /**
     * @return array{fields: array<string,mixed>, lock_version: int, outcome: PaletteOutcome}
     * @throws \Thallo\Core\Content\Services\VersionNotFound
     */
    public function restore(string $uuid, string $locale, string $versionUuid, int $lockVersion, ?string $actor): array
    {
        $version = $this->versions->findVersionByUuid($versionUuid);
        if ($version === null || (string) $version['entry_uuid'] !== $uuid || (string) $version['locale'] !== $locale) {
            throw new VersionNotFound($versionUuid);
        }
        $entry = $this->entries->findEntry($uuid) ?? throw new VersionNotFound($versionUuid);
        $typeUuid = (string) $entry['content_type_uuid'];
        $schema = $this->types->schemaFor($typeUuid);
        $projected = (array) $version['fields'];
        if ($this->blockRestore !== null) {
            [$projected] = $this->blockRestore->project($projected, $schema, (string) $version['created_at']);
        }
        unset($projected['_schema']);
        $current = (array) ($this->entries->findDraft($uuid, $locale)['fields'] ?? []);
        $fields = array_key_exists('_schema', $current) ? ['_schema' => $current['_schema']] + $projected : $projected;
        $clean = $this->validator->validate($schema, $fields);
        $basis = array_filter(
            $this->normalizer->basisOf(ColorTokenWalker::KIND_ENTRY, $schema, $projected),
            static fn (array $tokens): bool => array_filter($tokens, static fn (string $t): bool
                => Palette::slotOf($t) !== null) !== [],
        );
        $type = $this->types->findByUuid($typeUuid);
        $outcome = $this->entries->saveDraft(
            $uuid,
            $locale,
            $clean,
            (int) ($type['schema_version'] ?? 1),
            $lockVersion,
            $actor,
            extraBasis: $basis,
            recordRestoreBasis: $basis,
        );
        return [
            'fields' => (array) ($this->entries->findDraft($uuid, $locale)['fields'] ?? []),
            'lock_version' => $lockVersion + 1,
            'outcome' => $outcome,
        ];
    }
}

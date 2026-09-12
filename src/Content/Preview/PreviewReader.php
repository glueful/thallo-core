<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Preview;

use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Core\Content\Repositories\VersionRepository;
use Thallo\Core\Content\Schema\Migration\SchemaProjector;
use Glueful\Bootstrap\ApplicationContext;

/**
 * Verifies a preview token and resolves the ONE draft (or pinned version) it names.
 *
 * This is the only door to draft/unpinned content: the public delivery API cannot
 * see drafts at all. The reader therefore fails closed on every error path —
 *  - a verification failure (malformed / bad signature / expired) propagates the
 *    PreviewTokenException unchanged (the controller branches 403 vs 410); it is
 *    NEVER swallowed into a partial read.
 *  - a missing draft, a missing version, or a version that does not belong to the
 *    token's {entry, locale} throws PreviewNotFoundException (controller → 404).
 * It NEVER falls back to published-or-any other content. The only data it returns
 * is the draft/version the verified token explicitly names.
 */
final class PreviewReader
{
    use ResolvesPreviewKey;

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly EntryRepository $entries,
        private readonly VersionRepository $versions,
        private readonly ?SchemaProjector $projector = null,
    ) {
    }

    /**
     * @return array{entry_uuid:string,locale:string,version_uuid:?string,
     *               version:?int,schema_version:int,fields:array<string,mixed>}
     */
    public function read(string $token): array
    {
        // Propagates PreviewTokenException (malformed/invalid/expired). Do NOT catch:
        // the controller maps the kind to the right status. Fail closed.
        $payload = PreviewToken::verify($token, $this->previewKey($this->context), time());

        return $payload->versionUuid !== null
            ? $this->readVersion($payload)
            : $this->readDraft($payload);
    }

    /**
     * Read the draft/pinned version for an ALREADY-VERIFIED session (preview-sessions
     * spec §2) — same result shape as read(), no signature work. The token-based
     * read() stays for the JSON door.
     *
     * @return array{entry_uuid:string,locale:string,version_uuid:?string,
     *               version:?int,schema_version:int,fields:array<string,mixed>}
     */
    public function readVerified(\Thallo\Contracts\Delivery\PreviewSession $session): array
    {
        $payload = PreviewToken::fromVerifiedClaims(
            $session->entry,
            $session->locale,
            $session->version,
            $session->expiresAt,
            $session->theme,
        );
        return $payload->versionUuid !== null
            ? $this->readVersion($payload)
            : $this->readDraft($payload);
    }

    /**
     * @return array{entry_uuid:string,locale:string,version_uuid:string,
     *               version:int,schema_version:int,fields:array<string,mixed>}
     */
    private function readVersion(PreviewToken $payload): array
    {
        $version = $this->versions->findVersionByUuid((string) $payload->versionUuid);

        // Security: a pinned-preview token names {entry, locale, version}. The mint
        // endpoint takes version_uuid from the request body, so a caller could name a
        // version belonging to a DIFFERENT entry. The signature stops them re-pointing
        // the token, but the reader must still confirm the version actually belongs to
        // the token's entry+locale before serving it. Mismatch → not-found (never serve
        // another entry's content).
        if (
            $version === null
            || (string) $version['entry_uuid'] !== $payload->entryUuid
            || (string) $version['locale'] !== $payload->locale
        ) {
            throw new PreviewNotFoundException('Preview version not found for this entry/locale.');
        }

        return [
            'entry_uuid' => $payload->entryUuid,
            'locale' => $payload->locale,
            'version_uuid' => (string) $version['uuid'],
            'version' => (int) $version['version'],
            'schema_version' => (int) $version['schema_version'],
            'fields' => $this->projectFields(
                $payload->entryUuid,
                (int) $version['schema_version'],
                (array) $version['fields'],
            ),
        ];
    }

    /**
     * @return array{entry_uuid:string,locale:string,version_uuid:null,
     *               version:null,schema_version:int,fields:array<string,mixed>}
     */
    private function readDraft(PreviewToken $payload): array
    {
        $draft = $this->entries->findDraft($payload->entryUuid, $payload->locale);
        if ($draft === null) {
            throw new PreviewNotFoundException('Preview draft not found for this entry/locale.');
        }

        return [
            'entry_uuid' => $payload->entryUuid,
            'locale' => $payload->locale,
            'version_uuid' => null,
            'version' => null,
            'schema_version' => (int) $draft['schema_version'],
            'fields' => $this->projectFields(
                $payload->entryUuid,
                (int) $draft['schema_version'],
                (array) $draft['fields'],
            ),
        ];
    }

    /**
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    private function projectFields(string $entryUuid, int $schemaVersion, array $fields): array
    {
        if ($this->projector === null) {
            return $fields;
        }

        $entry = $this->entries->findEntry($entryUuid);
        if ($entry === null) {
            return $fields;
        }

        return $this->projector->project((string) $entry['content_type_uuid'], $schemaVersion, $fields);
    }
}

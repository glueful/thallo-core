<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Layouts;

use Thallo\Contracts\Layouts\LayoutReader;
use Thallo\Core\Content\Repositories\ContentTypeRepository;
use Thallo\Core\Content\Repositories\EntryRepository;

/**
 * Whether an entry renders through its type's layout (type layouts spec §6.3): its type has one and
 * the document does not opt out (`_presentation.use_layout: false`). The Design view learns it from
 * the mint and from every accepted apply, so the strip and the controls follow the document.
 */
final class EntryLayoutStatus
{
    public function __construct(
        private readonly LayoutReader $layouts,
        private readonly EntrySurface $surface,
        private readonly EntryRepository $entries,
        private readonly ContentTypeRepository $types,
    ) {
    }

    /**
     * @param array<string,mixed> $fields the document: its `_presentation` decides the opt-out
     * @return array{surface: string, target: string, label: string}|null
     */
    public function effective(string $typeSlug, array $fields): ?array
    {
        $presentation = is_array($fields['_presentation'] ?? null) ? $fields['_presentation'] : [];
        if ($typeSlug === '' || ($presentation['use_layout'] ?? true) === false) {
            return null;
        }
        if ($this->layouts->for('entry', $typeSlug) === null) {
            return null;
        }
        return ['surface' => 'entry', 'target' => $typeSlug, 'label' => $this->surface->label($typeSlug)];
    }

    /**
     * @param array<string,mixed>|null $fields the accepted working copy, when there is one; the
     *        stored draft otherwise
     * @return array{surface: string, target: string, label: string}|null
     */
    public function forEntry(string $entryUuid, string $locale, ?array $fields = null): ?array
    {
        $entry = $this->entries->findEntry($entryUuid);
        $type = $entry === null ? null : $this->types->findByUuid((string) $entry['content_type_uuid']);
        if ($type === null) {
            return null;
        }
        $fields ??= $this->entries->findDraft($entryUuid, $locale)['fields'] ?? [];
        return $this->effective((string) $type['slug'], $fields);
    }
}

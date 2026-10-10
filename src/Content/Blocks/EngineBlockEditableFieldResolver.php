<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Blocks;

use Thallo\Contracts\Content\BlockEditableFieldResolver;

/**
 * Server-side mirror of the client prose convention (edit-in-place spec §1;
 * admin proseDetection.ts is the byte-for-byte reference): a block type whose
 * schema is EXACTLY one `text` field with `format: rich` — beside, at most, a
 * link (`url` string, `new_tab` boolean) — is prose, and that field is
 * in-place editable. Reads through the repository's per-request
 * schema memo, so per-block resolution during a render is cheap.
 */
final class EngineBlockEditableFieldResolver implements BlockEditableFieldResolver
{
    /** A block's link, which a prose block may carry beside its text. */
    private const LINK_FIELDS = ['url' => 'string', 'new_tab' => 'boolean'];

    public function __construct(private readonly BlockTypeRepository $blockTypes)
    {
    }

    public function editableRichField(string $typeSlug): ?string
    {
        $schema = $this->blockTypes->schemasBySlug()[$typeSlug] ?? null;
        if ($schema === null) {
            return null;
        }
        $fields = array_values(array_filter(
            $schema->fields(),
            static fn ($field): bool => (self::LINK_FIELDS[$field->name()] ?? null) !== $field->type(),
        ));
        if (count($fields) !== 1) {
            return null;
        }
        $only = $fields[0];
        return $only->type() === 'text' && $only->format() === 'rich' ? $only->name() : null;
    }
}

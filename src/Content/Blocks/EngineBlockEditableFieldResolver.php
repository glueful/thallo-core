<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Blocks;

use Thallo\Contracts\Content\BlockEditableFieldResolver;

/**
 * Server-side mirror of the client prose convention (edit-in-place spec §1;
 * admin proseDetection.ts is the byte-for-byte reference): a block type whose
 * schema is EXACTLY one `text` field with `format: rich` is prose, and that
 * field is in-place editable. Reads through the repository's per-request
 * schema memo, so per-block resolution during a render is cheap.
 */
final class EngineBlockEditableFieldResolver implements BlockEditableFieldResolver
{
    public function __construct(private readonly BlockTypeRepository $blockTypes)
    {
    }

    public function editableRichField(string $typeSlug): ?string
    {
        $schema = $this->blockTypes->schemasBySlug()[$typeSlug] ?? null;
        if ($schema === null) {
            return null;
        }
        $fields = $schema->fields();
        if (count($fields) !== 1) {
            return null;
        }
        $only = $fields[0];
        return $only->type() === 'text' && $only->format() === 'rich' ? $only->name() : null;
    }
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs\Responses\Patterns;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only schema holder: one pattern of the section and page library. NEVER constructed at
 * runtime.
 */
final class PatternData implements ResponseData
{
    public function __construct(
        public readonly string $slug,
        /** `section` (one block) or `page` (several sections). */
        public readonly string $kind,
        public readonly string $label,
        /** The group it is listed under; `Pages` for a page, `Header` or `Footer` for a template. */
        public readonly string $category,
        public readonly string $description,
        /**
         * What an editor chooses after inserting it: `product` for a shop pattern whose product
         * block needs a product; null otherwise.
         */
        public readonly ?string $requires,
        /** @var list<array<string,mixed>> Block trees with no ids — the editor mints them. */
        public readonly array $blocks,
        /** Where it is offered: `page` (a page body), `region` (the header or footer) or `layout`. */
        public readonly string $scope,
        /** `header` or `footer` for a region's pattern; null otherwise. */
        public readonly ?string $region,
        /** The layout surface of a `layout` pattern (`entry`, `listing`, `product`…); null otherwise. */
        public readonly ?string $surface,
        /**
         * @var array<string,string>|null A layout template's Frame settings (`width`, `header`,
         * `footer`); null for every other pattern.
         */
        public readonly ?array $settings,
        /**
         * @var array<string,string>|null A section saved from a layout: the labels of the fields it
         * shows, as the type it was saved from names them; null for every other pattern.
         */
        public readonly ?array $field_labels,
        /** True for a section this site saved from the stage; false for a shipped pattern. */
        public readonly bool $saved,
        /** A saved section's id, for renaming and deleting it; null for a shipped pattern. */
        public readonly ?string $id,
    ) {
    }
}

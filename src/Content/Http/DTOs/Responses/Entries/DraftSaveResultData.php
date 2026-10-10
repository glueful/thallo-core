<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs\Responses\Entries;

use Glueful\Http\Contracts\ResponseData;

/** The saved draft, and whether the save cleared the preview working copy (visual builder spec §3.5). */
final class DraftSaveResultData implements ResponseData
{
    public function __construct(
        public readonly DraftData $draft,
        public readonly bool $preview_cleared,
        /**
         * @var list<array{location: string, from: string, to: string}> What the palette normalised on save
         *      (custom palette spec §4.5): each colour a running replacement mapped, located by block id.
         */
        public readonly array $palette_rewrites,
        /** The palette generation the save held (custom palette plan Task 12). */
        public readonly int $palette_generation,
        /**
         * @var array<string,mixed> The complete batch of replacement records newer than the request's
         *      `palette_through`, bounded by `palette_generation` — or `{expired: true}`.
         */
        public readonly array $palette_replacements,
    ) {
    }
}

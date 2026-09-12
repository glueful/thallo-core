<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs\Responses\Entries;

use Glueful\Http\Contracts\ResponseData;

/**
 * Doc-only shape of one admin entry-list row (see EntryController::index). Display title is
 * derived by convention; status is the coarse editorial state (draft|scheduled|published).
 */
final class EntryListItemData implements ResponseData
{
    /** @param list<string> $locales */
    public function __construct(
        public readonly string $uuid,
        public readonly string $display_title,
        public readonly string $status,
        public readonly array $locales,
        public readonly ?string $updated_at,
    ) {
    }
}

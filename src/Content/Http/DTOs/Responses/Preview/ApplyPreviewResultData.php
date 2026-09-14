<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs\Responses\Preview;

use Glueful\Http\Contracts\ResponseData;

/**
 * The accepted apply (visual builder spec §3.5): the epoch and revision now rendered, the
 * stage baseline the patch expects (the revision before this one), the site style generation
 * the render used, and — on the fragment path — the affected roots' markup by block id
 * (null: refresh the whole page).
 */
final class ApplyPreviewResultData implements ResponseData
{
    public function __construct(
        public readonly string $epoch,
        public readonly int $revision,
        public readonly int $baseline,
        public readonly int $style_generation,
        public readonly \DateTimeInterface $applied_at,
        /** @var array<string,string>|null Root block id => annotated markup; null = whole page. */
        public readonly ?array $fragments = null,
    ) {
    }
}

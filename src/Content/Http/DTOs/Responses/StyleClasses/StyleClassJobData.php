<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Http\DTOs\Responses\StyleClasses;

use Glueful\Http\Contracts\ResponseData;

/** Doc-only schema holder: one style class job (visual builder spec §4.5). NEVER constructed at runtime. */
final class StyleClassJobData implements ResponseData
{
    /** @param list<array{source: string, id: string, locale: string|null, reason: string}> $failure_report */
    public function __construct(
        public readonly string $id,
        public readonly string $class_id,
        /** The class version the job was queued against; a class edited since fails the job. */
        public readonly int $class_version,
        /** `detach` or `remove`. */
        public readonly string $kind,
        /** `running`, `completed` or `failed`. */
        public readonly string $status,
        public readonly int $passes,
        public readonly int $work_items_total,
        public readonly int $work_items_done,
        public readonly int $work_items_failed,
        public readonly array $failure_report,
        public readonly ?string $created_at,
        public readonly ?string $finished_at,
    ) {
    }
}

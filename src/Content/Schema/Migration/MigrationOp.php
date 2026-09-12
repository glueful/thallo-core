<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Schema\Migration;

interface MigrationOp
{
    /**
     * Apply the op during eager materialization.
     *
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    public function apply(array $fields): array;

    /**
     * Apply the op during read-time projection.
     *
     * Projection is defensive: if the target field already exists, it wins and the old
     * source key is dropped, so a partially materialized row never fails a read.
     *
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    public function applyForProjection(array $fields): array;

    /** @return array<string,string> */
    public function toArray(): array;
}

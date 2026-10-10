<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Palette;

/** One replace job (custom palette spec §4.4): a slot being replaced, where to, and its progress. */
final class PaletteJob
{
    /** @param list<array{source: string, id: string, locale: ?string, reason: string}> $failureReport */
    public function __construct(
        public readonly string $id,
        public readonly int $slot,
        public readonly string $to,
        public readonly ?string $contrastTo,
        public readonly string $status,
        public readonly int $passes,
        public readonly int $total,
        public readonly int $done,
        public readonly int $failed,
        public readonly array $failureReport,
        public readonly ?string $workspace = null,
        public readonly ?string $createdBy = null,
        public readonly ?string $heartbeatAt = null,
        public readonly ?int $completedGeneration = null,
        public readonly ?string $createdAt = null,
        public readonly ?string $finishedAt = null,
        /** @var array<string,int> documents rewritten, per source, across every run */
        public readonly array $counts = [],
    ) {
    }
}

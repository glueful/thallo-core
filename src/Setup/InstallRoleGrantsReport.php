<?php

declare(strict_types=1);

namespace Thallo\Core\Setup;

/** Outcome of one {@see InstallRoleGrants::apply()} run. */
final class InstallRoleGrantsReport
{
    /** @param array<string,int> $granted role slug => permissions newly granted this run */
    public function __construct(
        public readonly int $declared,
        public readonly array $granted,
    ) {
    }
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Content\DocsSync;

use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;
use Psr\Log\LoggerInterface;

/**
 * Runs a recurring docs sync task in every workspace, or once for a site without workspaces, as
 * the font library's upgrade step does. One workspace's failure is logged and never stops the
 * others (forEachTenant() itself stops at the first that throws).
 */
final class DocsSyncWorkspaces
{
    public function __construct(
        private readonly ?TenantContextRunner $runner,
        private readonly bool $tenancyEnabled,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /** @param callable(): void $fn */
    public function each(string $task, callable $fn): int
    {
        if (!$this->tenancyEnabled || $this->runner === null) {
            $this->guarded($task, null, $fn);
            return 1;
        }
        $count = 0;
        $this->runner->forEachTenant(function (string $tenant) use ($task, $fn, &$count): void {
            $count++;
            $this->guarded($task, $tenant, $fn);
        });
        return $count;
    }

    /** @param callable(): void $fn */
    private function guarded(string $task, ?string $workspace, callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->logger?->error('Docs sync ' . $task . ' failed in a workspace; the others go on', [
                'workspace' => $workspace,
                'error' => $e->getMessage(),
            ]);
        }
    }
}

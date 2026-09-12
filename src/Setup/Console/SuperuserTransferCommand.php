<?php

declare(strict_types=1);

namespace Thallo\Core\Setup\Console;

use Thallo\Core\Support\AuthorityAudit;
use Thallo\Core\Support\AuthorityContinuityGuard;
use Thallo\Core\Support\AuthorityMutator;
use Thallo\Core\Support\RoleAuthority;
use Glueful\Console\BaseCommand;
use Glueful\Extensions\Users\Repositories\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'thallo:superuser:transfer',
    description: 'Atomically transfer hidden-root superuser authority between active users.',
)]
final class SuperuserTransferCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('from-user-uuid', InputArgument::REQUIRED, 'Current superuser UUID');
        $this->addArgument('to-user-uuid', InputArgument::REQUIRED, 'Destination user UUID');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Proceed without confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $from = (string) $input->getArgument('from-user-uuid');
        $to = (string) $input->getArgument('to-user-uuid');
        if ($from === $to) {
            $this->error('Source and destination must differ.');
            return self::FAILURE;
        }
        $users = $this->getService(UserRepository::class);
        if (!$this->isActiveUser($users->findByUuid($from))) {
            $this->error("Source user {$from} not found or inactive.");
            return self::FAILURE;
        }
        if (!$this->isActiveUser($users->findByUuid($to))) {
            $this->error("Destination user {$to} not found or inactive.");
            return self::FAILURE;
        }

        $authority = $this->getService(RoleAuthority::class);
        if ($authority->isCanonicalSuperuser($to) && !$authority->isCanonicalSuperuser($from)) {
            $this->success('Transfer already complete; nothing to do.');
            return self::SUCCESS;
        }
        if (!$authority->isCanonicalSuperuser($from)) {
            $this->error("Source user {$from} is not an active superuser.");
            return self::FAILURE;
        }
        $force = (bool) $input->getOption('force');
        if (!$force && !$this->isInteractive()) {
            $this->error('Refusing to run non-interactively without --force.');
            return self::FAILURE;
        }
        if (!$force && !$this->confirm("Transfer superuser from {$from} to {$to}?", false)) {
            $this->info('Aborted.');
            return self::SUCCESS;
        }

        $mutator = $this->getService(AuthorityMutator::class);
        try {
            $this->getService(AuthorityContinuityGuard::class)->runExclusive(
                static function () use ($mutator, $from, $to): void {
                    if (!$mutator->assignRole($to, 'superuser')) {
                        throw new \RuntimeException('Failed to assign superuser to destination.');
                    }
                    if (!$mutator->assignRole($to, 'administrator')) {
                        throw new \RuntimeException('Failed to assign administrator to destination.');
                    }
                    if (!$mutator->revokeRole($from, 'superuser')) {
                        throw new \RuntimeException('Failed to revoke superuser from source.');
                    }
                }
            );
        } catch (\Throwable $e) {
            $this->error('Superuser transfer failed; no roles were changed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->getService(AuthorityAudit::class)->record(
            'security.superuser_transferred',
            'system:console',
            $to,
            ['from_user_uuid' => $from, 'to_user_uuid' => $to, 'source' => 'cli'],
        );
        $this->success("Transferred superuser from {$from} to {$to}.");
        return self::SUCCESS;
    }

    /** @param array<string,mixed>|null $user */
    private function isActiveUser(?array $user): bool
    {
        return $user !== null
            && ($user['status'] ?? null) === 'active'
            && ($user['deleted_at'] ?? null) === null;
    }
}

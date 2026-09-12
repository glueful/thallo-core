<?php

declare(strict_types=1);

namespace Thallo\Core\Setup\Console;

use Thallo\Core\Support\AuthorityAudit;
use Thallo\Core\Support\AuthorityContinuityGuard;
use Thallo\Core\Support\AuthorityMutator;
use Glueful\Console\BaseCommand;
use Glueful\Extensions\Users\Repositories\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'thallo:superuser:grant',
    description: 'Grant hidden-root superuser authority to an active user.',
)]
final class SuperuserGrantCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addArgument('user-uuid', InputArgument::REQUIRED, 'The user UUID to promote');
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Proceed without confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $uuid = (string) $input->getArgument('user-uuid');
        $user = $this->getService(UserRepository::class)->findByUuid($uuid);
        if (!$this->isActiveUser($user)) {
            $this->error("No active user with UUID {$uuid}.");
            return self::FAILURE;
        }
        $force = (bool) $input->getOption('force');
        if (!$force && !$this->isInteractive()) {
            $this->error('Refusing to run non-interactively without --force.');
            return self::FAILURE;
        }
        $label = (string) ($user['email'] ?? $uuid);
        if (!$force && !$this->confirm("Grant superuser + administrator to {$label}?", false)) {
            $this->info('Aborted.');
            return self::SUCCESS;
        }

        $mutator = $this->getService(AuthorityMutator::class);
        try {
            $this->getService(AuthorityContinuityGuard::class)->runExclusive(
                static function () use ($mutator, $uuid): void {
                    foreach (['superuser', 'administrator'] as $slug) {
                        if (!$mutator->assignRole($uuid, $slug)) {
                            throw new \RuntimeException("Failed to assign required role '{$slug}'.");
                        }
                    }
                }
            );
        } catch (\Throwable $e) {
            $this->error('Superuser grant failed; no roles were changed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->getService(AuthorityAudit::class)->record(
            'security.superuser_granted',
            'system:console',
            $uuid,
            ['roles' => ['superuser', 'administrator'], 'source' => 'cli'],
        );
        $this->success("Granted superuser + administrator to {$label}.");
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

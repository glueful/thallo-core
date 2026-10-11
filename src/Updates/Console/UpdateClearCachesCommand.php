<?php

declare(strict_types=1);

namespace Thallo\Core\Updates\Console;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Glueful\Console\BaseCommand;
use Glueful\Routing\RouteCache;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Thallo\Core\Setup\UpgradeCaches;

/**
 * Clears what outlives a release (plan ruling 9): the compiled routes, the Twig and container caches,
 * the rendered pages and the shop's cached pages. The update runner runs it at reopen and after a
 * code rollback, under whichever code is installed then.
 */
#[AsCommand(
    name: 'thallo:update:clear-caches',
    description: 'Clear the caches an update or rollback leaves stale (used by the update runner)',
)]
final class UpdateClearCachesCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var ApplicationContext $context */
        $context = $this->getService(ApplicationContext::class);
        /** @var CacheStore $cache */
        $cache = $this->getService(CacheStore::class);
        $cleared = (new UpgradeCaches(
            $this->getService(RouteCache::class),
            $cache,
            $context->getBasePath() . '/storage/cache/twig',
            $context->getBasePath() . '/storage/cache/container',
        ))->clear();
        $cache->invalidateTags(['thallo:render:page', 'thallo:shop:catalog']);
        $output->writeln('Caches cleared: ' . implode(', ', array_merge($cleared, ['page tags'])) . '.');
        return self::SUCCESS;
    }
}

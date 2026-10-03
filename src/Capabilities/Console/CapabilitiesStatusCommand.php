<?php

declare(strict_types=1);

namespace Thallo\Core\Capabilities\Console;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Thallo\Core\Capabilities\Activation\ActivationStore;
use Thallo\Core\Capabilities\CapabilityStateStore;
use Thallo\Core\Capabilities\FeatureManagementPolicy;

/** Where every feature with an activation flow stands: on, off, preparing or failed, and why. */
#[AsCommand(name: 'thallo:capabilities:status', description: 'Show where each capability with an activation stands')]
final class CapabilitiesStatusCommand extends BaseCommand
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $store = $this->getService(ActivationStore::class);
        $states = $this->getService(CapabilityStateStore::class);
        $rows = [];
        foreach ($this->getService(FeatureManagementPolicy::class)->activationCapabilities() as $id) {
            $record = $store->find($id);
            $open = $record !== null && $record->isOpen();
            $state = $open ? $record->status : ($states->storedFresh($id) === true ? 'on' : 'off');
            $rows[] = [
                $id,
                $state,
                $open ? ($record->failedStep ?? $record->nextStep() ?? '—') : '—',
                $open ? ($record->error ?? '—') : '—',
                $open ? ($record->remedy ?? '—') : '—',
            ];
        }
        (new Table($output))->setHeaders(['Capability', 'State', 'Step', 'Error', 'Remedy'])->setRows($rows)->render();
        return self::SUCCESS;
    }
}

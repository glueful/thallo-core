<?php

declare(strict_types=1);

namespace Thallo\Core\Capabilities\Console;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Thallo\Core\Capabilities\Activation\ActivationStore;
use Thallo\Core\Capabilities\FeatureManagementPolicy;

/**
 * Finishes turning on a feature (or every feature being turned on) from its next step, in this
 * fresh process: after `--prepare` at deploy time, after a failed step was fixed, or from
 * provision. When it retries the engine step it continues in another fresh process.
 */
#[AsCommand(name: 'thallo:features:resume', description: 'Finish turning on a feature that is being prepared')]
final class FeaturesResumeCommand extends BaseCommand
{
    use ContinuesActivations;

    protected function configure(): void
    {
        $this->addArgument('capability', InputArgument::OPTIONAL, 'The feature; every open one when omitted');
        $this->addOption('hop', null, InputOption::VALUE_REQUIRED, 'Internal: fresh processes started so far', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $policy = $this->getService(FeatureManagementPolicy::class);
        $store = $this->getService(ActivationStore::class);
        $hop = max(0, (int) $input->getOption('hop'));

        $given = trim((string) ($input->getArgument('capability') ?? ''));
        if ($given !== '' && $policy->labelOf($given) === null) {
            $this->error("{$given} doesn't turn on through activation.");
            return self::FAILURE;
        }
        $ids = $given !== '' ? [$given] : array_values(array_filter(
            $policy->activationCapabilities(),
            static fn (string $id): bool => $store->find($id)?->isOpen() === true,
        ));
        if ($ids === []) {
            $this->line('Nothing to resume: no feature is being turned on.');
            return self::SUCCESS;
        }

        $exit = self::SUCCESS;
        foreach ($ids as $id) {
            $label = (string) $policy->labelOf($id);
            $record = $store->find($id);
            if ($record === null || !$record->isOpen()) {
                $this->line("{$label} isn't being turned on.");
                continue;
            }
            $this->line("Resuming {$label}…");
            $outcome = $this->runActivation($id, $record->generation, true);
            $code = is_int($outcome) ? $outcome : $this->settle($output, $id, $label, $outcome, false, $hop);
            if ($code !== self::SUCCESS) {
                $exit = self::FAILURE;
            }
        }
        return $exit;
    }
}

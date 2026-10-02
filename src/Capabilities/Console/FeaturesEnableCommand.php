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
 * Turns a feature on from a shell, as the Features page does: prepares its engine, then continues
 * in a fresh process (the engine's provider only boots there) through its blocks, its permissions
 * and the switch. `--prepare` stops after the engine, for deploy time on a host whose application
 * files are read-only at runtime; the running site then finishes it.
 */
#[AsCommand(name: 'thallo:features:enable', description: 'Turn a feature on, preparing everything it needs')]
final class FeaturesEnableCommand extends BaseCommand
{
    use ContinuesActivations;

    protected function configure(): void
    {
        $this->addArgument('capability', InputArgument::REQUIRED, 'The feature, e.g. thallo.commerce');
        $this->addOption('prepare', null, InputOption::VALUE_NONE, 'Prepare the engine only (deploy time)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id = trim((string) $input->getArgument('capability'));
        $policy = $this->getService(FeatureManagementPolicy::class);
        $label = $policy->labelOf($id);
        if ($label === null) {
            $this->error(
                "{$id} doesn't turn on through activation. These do: "
                . implode(', ', $policy->activationCapabilities())
                . '. Others switch with `php glueful thallo:capabilities --enable=<id>`.'
            );
            return self::FAILURE;
        }

        $record = $this->getService(ActivationStore::class)->startOrJoin($id, 'cli');
        $this->line("Turning on {$label}…");
        $outcome = $this->runActivation($id, $record->generation, false);
        if (is_int($outcome)) {
            return $outcome;
        }
        return $this->settle($output, $id, $label, $outcome, (bool) $input->getOption('prepare'), 0);
    }
}

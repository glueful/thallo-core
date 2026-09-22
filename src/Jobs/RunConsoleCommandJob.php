<?php

declare(strict_types=1);

namespace Thallo\Core\Jobs;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Console\BaseCommand;
use Glueful\Queue\Job;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Runs one console command from `config/schedule.php`, so the maintenance commands an operator
 * would otherwise have to remember (pruning, cleanup, sweeps) run on the scheduler. Parameters:
 * `command` (the command's class) and optional `arguments` (options as `--name` => value).
 *
 * The command is resolved by class, as the console itself resolves an extension's commands: from
 * the container when bound, else built with the booted container and context. A class that does
 * not exist, because its extension is not installed, is skipped rather than failed.
 */
final class RunConsoleCommandJob extends Job
{
    public function handle(): void
    {
        if ($this->context === null || !$this->context->hasContainer()) {
            return;
        }
        $data = $this->getData();
        $class = is_string($data['command'] ?? null) ? $data['command'] : '';
        $command = $this->command($class);
        if ($command === null) {
            return;
        }
        /** @var array<string,mixed> $arguments */
        $arguments = is_array($data['arguments'] ?? null) ? $data['arguments'] : [];

        $input = new ArrayInput($arguments);
        $input->setInteractive(false);
        $output = new BufferedOutput();
        $exit = $command->run($input, $output);
        if ($exit !== 0) {
            throw new \RuntimeException(sprintf(
                'Scheduled command %s exited with %d: %s',
                (string) $command->getName(),
                $exit,
                trim($output->fetch()),
            ));
        }
    }

    private function command(string $class): ?Command
    {
        if ($class === '' || !class_exists($class) || !is_subclass_of($class, Command::class)) {
            return null;
        }
        $container = $this->context?->getContainer();
        if ($container === null) {
            return null;
        }
        if ($container->has($class)) {
            $command = $container->get($class);
        } elseif (is_subclass_of($class, BaseCommand::class)) {
            $command = new $class($container, $this->context instanceof ApplicationContext ? $this->context : null);
        } else {
            $command = new $class();
        }

        return $command instanceof Command ? $command : null;
    }
}

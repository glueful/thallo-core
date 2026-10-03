<?php

declare(strict_types=1);

namespace Thallo\Core\Capabilities\Console;

use Symfony\Component\Console\Output\OutputInterface;
use Thallo\Core\Capabilities\Activation\ActivationInProgress;
use Thallo\Core\Capabilities\Activation\ActivationOutcome;
use Thallo\Core\Capabilities\Activation\ActivationRecord;
use Thallo\Core\Capabilities\Activation\ActivationRunner;
use Thallo\Core\Capabilities\Activation\ActivationStatus;
use Thallo\Core\Capabilities\Activation\ActivationSuperseded;

/**
 * What thallo:capabilities:enable and :resume share: one runner call, and what to do with where it
 * stopped — report a failure with its fix, report success from the operation's result, stop for
 * --prepare, or continue in a fresh process (at most MAX_HOPS in a row).
 */
trait ContinuesActivations
{
    private const MAX_HOPS = 3;

    /** The runner's outcome, or an exit code when the run was refused. */
    private function runActivation(string $id, int $generation, bool $freshBoot): ActivationOutcome|int
    {
        try {
            return $this->getService(ActivationRunner::class)->run($id, $generation, $freshBoot);
        } catch (ActivationSuperseded) {
            $this->error("{$id} was turned off or restarted while this ran; nothing more was done.");
            return self::FAILURE;
        } catch (ActivationInProgress) {
            $this->error("{$id} is being turned on by another request. See `php glueful thallo:capabilities:status`.");
            return self::FAILURE;
        }
    }

    private function settle(
        OutputInterface $output,
        string $id,
        string $label,
        ActivationOutcome $outcome,
        bool $prepareOnly,
        int $hop,
    ): int {
        $record = $outcome->record;
        if ($record->status === ActivationStatus::FAILED) {
            $this->error("{$label} couldn't be turned on: {$record->failedStep} failed: {$record->error}");
            if ($record->remedy !== null) {
                $this->line("Fix: {$record->remedy}");
            }
            $this->line("Then run `php glueful thallo:capabilities:resume {$id}`.");
            return self::FAILURE;
        }
        if ($record->status === ActivationStatus::SUCCEEDED) {
            $this->success(self::summary($label, $record));
            return self::SUCCESS;
        }
        if (!$outcome->needsBoot) {
            return self::SUCCESS;
        }
        if ($prepareOnly) {
            $this->success(
                "Prepared. Finish on the running site: Extensions, or `php glueful thallo:capabilities:resume {$id}`."
            );
            return self::SUCCESS;
        }
        if ($hop >= self::MAX_HOPS) {
            $this->error(
                "{$label} still needs a fresh process after {$hop}; run `php glueful thallo:capabilities:resume {$id}`."
            );
            return self::FAILURE;
        }
        $this->line('Continuing in a fresh process…');
        $exit = $this->getService(FreshProcess::class)->glueful(
            ['thallo:capabilities:resume', $id, '--hop=' . ($hop + 1)],
            static function (string $line) use ($output): void {
                $output->writeln($line);
            },
        );
        return $exit === 0 ? self::SUCCESS : self::FAILURE;
    }

    private static function summary(string $label, ActivationRecord $record): string
    {
        $blocks = (int) ($record->result['blocks_created'] ?? 0);
        $grants = (array) ($record->result['grants'] ?? []);
        $granted = array_sum(array_map('intval', $grants));
        $summary = sprintf('%s is on. Added %d %s', $label, $blocks, $blocks === 1 ? 'block' : 'blocks');
        if ($granted > 0) {
            $summary .= sprintf(' and granted %d new %s', $granted, $granted === 1 ? 'permission' : 'permissions');
        }
        return $summary . '.';
    }
}

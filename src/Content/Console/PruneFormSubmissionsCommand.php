<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Console;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Thallo\Core\Content\Forms\FormSubmissionRepository;

/** Retention for form submissions: deletes those older than `forms.retention_days`. */
#[AsCommand(name: 'thallo:forms:prune', description: 'Delete form submissions past the retention window')]
final class PruneFormSubmissionsCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('days', null, InputOption::VALUE_REQUIRED, 'Override: delete submissions older than D days');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $option = $input->getOption('days');
        $days = is_scalar($option) && (string) $option !== ''
            ? (int) $option
            : (int) config($this->getContext(), 'forms.retention_days', 0);
        if ($days < 1) {
            $this->info('No retention set (FORMS_RETENTION_DAYS); submissions are kept.');
            return self::SUCCESS;
        }
        $deleted = $this->getService(FormSubmissionRepository::class)
            ->deleteOlderThan(gmdate('Y-m-d H:i:s', time() - $days * 86400));
        $this->success(sprintf('Deleted %d submission(s) older than %d days.', $deleted, $days));

        return self::SUCCESS;
    }
}

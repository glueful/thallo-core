<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Console;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Thallo\Core\Content\Style\Conversion\DecisionsFile;
use Thallo\Core\Content\Style\Conversion\SettingsConversion;

/**
 * `thallo:blocks:convert-settings` (visual builder spec §7.3): the dry run writes the
 * diagnostics report; the live run consumes the decisions file, refuses to complete while any
 * diagnostic is unresolved, converts every pending document for every pending stage and
 * persists each atomically with its schema stamp. Idempotent per stage.
 */
#[AsCommand(
    name: 'thallo:blocks:convert-settings',
    description: 'Convert legacy block presentation fields into typed settings (dry run by default with --dry-run).',
)]
final class ConvertSettingsCommand extends BaseCommand
{
    public const DEFAULT_DECISIONS = 'storage/conversion/decisions.json';

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Evaluate and write the diagnostics report only.');
        $this->addOption('report', null, InputOption::VALUE_REQUIRED, 'Where to write the report (JSON lines).');
        $this->addOption('decisions', null, InputOption::VALUE_REQUIRED, 'The decisions file to consume.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $base = $this->getContext()->getBasePath();
        $decisionsPath = $input->getOption('decisions');
        $decisionsPath = is_string($decisionsPath) && $decisionsPath !== ''
            ? $decisionsPath
            : $base . '/' . self::DEFAULT_DECISIONS;
        $reportPath = $input->getOption('report');
        $reportPath = is_string($reportPath) && $reportPath !== ''
            ? $reportPath
            : $base . '/storage/conversion/report-' . gmdate('Ymd-His') . '.jsonl';
        $dryRun = (bool) $input->getOption('dry-run');

        /** @var SettingsConversion $conversion */
        $conversion = $this->getService(SettingsConversion::class);
        $blocked = $conversion->blockedBy();
        if ($blocked !== null) {
            $this->error("A block type migration is in progress ({$blocked}); convert settings after it completes.");
            return self::FAILURE;
        }

        try {
            $decisions = DecisionsFile::load(is_file($decisionsPath) ? $decisionsPath : null);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
        $evaluation = $conversion->evaluate($decisions);
        $report = $evaluation['report'];
        $report->write($reportPath);
        $counts = $report->counts();
        $this->line(sprintf(
            'Documents pending: %d. Fields: converted %d, kept %d, superseded %d, discarded %d, unmappable %d.',
            $evaluation['pending'],
            $counts['converted'],
            $counts['kept'],
            $counts['superseded'],
            $counts['discarded'],
            $counts['unmappable'],
        ));
        $this->line("Report: {$reportPath}");

        if ($dryRun) {
            if ($evaluation['unresolved'] > 0) {
                $this->warning(sprintf(
                    '%d unresolved: record decisions in %s and run again.',
                    $evaluation['unresolved'],
                    $decisionsPath,
                ));
            }
            return self::SUCCESS;
        }
        if ($evaluation['unresolved'] > 0) {
            $this->error(sprintf(
                'Refusing to convert: %d unresolved diagnostics (see the report); record decisions in %s.',
                $evaluation['unresolved'],
                $decisionsPath,
            ));
            return self::FAILURE;
        }

        $result = $conversion->apply($evaluation);
        $this->line(sprintf(
            'Converted %d documents (%d already current).',
            $result['converted'],
            $result['unchanged'],
        ));
        if ($result['changed'] !== []) {
            $this->error(sprintf(
                '%d documents changed while converting and were left as they were — run again: %s',
                count($result['changed']),
                implode(', ', $result['changed']),
            ));
            return self::FAILURE;
        }
        $this->success('Settings conversion complete.');
        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Payments\Console;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Console\BaseCommand;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Glueful\Database\Connection;
use Thallo\Core\Payments\Tenancy\IntentRetirement;
use Thallo\Core\Payments\Tenancy\PaymentAdoptionRefusedException;
use Thallo\Core\Payments\Tenancy\PaymentAdoptionReport;
use Thallo\Core\Payments\Tenancy\PaymentTenancyAdoption;
use Thallo\Tenancy\Adoption\AdoptionGate;
use Thallo\Tenancy\Adoption\AdoptionGateBusyException;
use Thallo\Tenancy\System\SystemFlags;

/**
 * Repairs payments on a site that turned workspaces on before payments were adopted with the
 * schema flip: their rows stayed unassigned (tenant '') and the default workspace cannot see them.
 *
 * A dry run by default — per-table counts, every key the default workspace already holds, every
 * unassigned row another workspace owns, and every order with duplicate intents. `--apply` moves
 * the unassigned rows into the recorded default workspace through {@see PaymentTenancyAdoption}
 * (ids, relationships and existing assignments unchanged), or refuses without writing anything.
 * Duplicate intents are only reported: which one the customer paid is the gateway's answer, so
 * reconcile them there. Running it again after a repair finds nothing to do.
 */
#[AsCommand(
    name: 'thallo:tenancy:payments:repair',
    description: 'Move payments left without a workspace into the default workspace (dry run by default)',
)]
final class RepairPaymentTenancyCommand extends BaseCommand
{
    public function __construct(
        ContainerInterface $container,
        ApplicationContext $context,
        private ?PaymentTenancyAdoption $adoption = null,
        private ?SystemFlags $flags = null,
        private ?AdoptionGate $gate = null,
        private readonly int $gateTimeoutMs = 10000,
    ) {
        parent::__construct($container, $context);
    }

    protected function configure(): void
    {
        $this
            ->setHelp(
                "Finds payment rows that have no workspace on a site with workspaces turned on, and\n"
                . "moves them into the default workspace. A dry run unless --apply is given.\n\n"
                . "  thallo:tenancy:payments:repair\n"
                . "  thallo:tenancy:payments:repair --apply\n"
                . "  thallo:tenancy:payments:repair --json\n\n"
                . "Only rows with no workspace move; ids and relationships are kept. A key the default\n"
                . "workspace already holds, or a row another workspace owns, refuses the whole move.\n"
                . "Duplicate intents are listed for reconciliation with the gateway, never resolved."
            )
            ->addOption('apply', null, InputOption::VALUE_NONE, 'Move the rows (default: dry run)')
            ->addOption(
                'retire-intent',
                null,
                InputOption::VALUE_REQUIRED,
                'Supersede one payment intent (by uuid) instead of moving rows — a dry run unless --apply',
            )
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the report as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $flags = $this->flags ??= $this->getContainer()->get(SystemFlags::class);
        $adoption = $this->adoption ??= $this->getContainer()->get(PaymentTenancyAdoption::class);
        $apply = (bool) $input->getOption('apply');
        $json = (bool) $input->getOption('json');

        $flags->clearCache();
        if ($flags->schemaState() !== 'widened') {
            $output->writeln('<error>Workspaces are not turned on here, so payments have nothing to repair.</error>');
            return self::FAILURE;
        }
        $tenant = (string) $flags->defaultTenantUuid();
        if ($tenant === '') {
            $output->writeln(
                '<error>Workspaces are on but no default workspace is recorded; '
                . 'there is nowhere to move payments.</error>'
            );
            return self::FAILURE;
        }

        $retire = $input->getOption('retire-intent');
        if (is_string($retire) && $retire !== '') {
            return $this->retireIntent($output, $retire, $tenant, $apply);
        }

        $refused = false;
        try {
            $report = $apply ? $this->applyBehindTheGate($adoption, $tenant) : $adoption->diagnose($tenant);
        } catch (AdoptionGateBusyException) {
            $output->writeln(
                '<error>Payment work that began before this repair is still running (a request, or a '
                . 'queue worker that has handled a payment). Nothing was changed. Let it finish, or stop '
                . 'the queue workers, then run it again.</error>'
            );
            return self::FAILURE;
        } catch (PaymentAdoptionRefusedException $e) {
            $report = $e->report;
            $refused = true;
        } catch (\PDOException $e) {
            if (($e->errorInfo[0] ?? $e->getCode()) !== '55P03') {
                throw $e;
            }
            $output->writeln('<error>Payments are busy (a payment is being written); try again.</error>');
            return self::FAILURE;
        }

        if ($json) {
            $output->writeln((string) json_encode(
                $report->toArray() + ['applied' => $apply && !$refused],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ));
            return $report->refused() ? self::FAILURE : self::SUCCESS;
        }

        $this->render($output, $report);

        if ($refused) {
            $output->writeln('<error>Refused: nothing was changed. Resolve the rows above, then run it again.</error>');
            return self::FAILURE;
        }
        if (!$apply) {
            if ($report->refused()) {
                $output->writeln(
                    'Dry run: nothing was changed. --apply would refuse until the rows above are resolved.'
                );
                return self::FAILURE;
            }
            $output->writeln($report->unassignedTotal() === 0
                ? 'Dry run: nothing was changed. Nothing to repair: every payment row has a workspace.'
                : 'Dry run: nothing was changed. Run it again with --apply to move the unassigned rows.');
            return self::SUCCESS;
        }

        $moved = array_sum($report->moved);
        $output->writeln($moved === 0
            ? 'Nothing to repair: every payment row has a workspace.'
            : "Moved {$moved} unassigned row(s) into workspace {$tenant}.");
        $output->writeln("Payments' tables now refuse a row with no workspace.");

        return self::SUCCESS;
    }

    /**
     * Supersede one named intent — the way to clear a duplicate that blocks the move. Shows what the
     * intent is before anything else, reaches only unassigned intents and the default workspace's,
     * and retires through Payvia's conditional retirement ({@see IntentRetirement}).
     */
    private function retireIntent(OutputInterface $output, string $uuid, string $tenant, bool $apply): int
    {
        $retirement = new IntentRetirement(
            $this->getContainer()->get(Connection::class),
            $this->getContext(),
        );
        $intent = $retirement->find($uuid);
        if ($intent === null) {
            $output->writeln("<error>No payment intent {$uuid}.</error>");
            return self::FAILURE;
        }

        $owner = (string) $intent['tenant_uuid'];
        $output->writeln("Payment intent {$uuid}");
        $output->writeln('  Workspace:  ' . match ($owner) {
            '' => 'no workspace (unassigned)',
            $tenant => "the default workspace ({$tenant})",
            default => "another workspace ({$owner})",
        });
        $output->writeln("  Order:      {$intent['payable_type']} {$intent['payable_id']}");
        $output->writeln("  Status:     {$intent['status']}");
        $output->writeln("  Provider:   {$intent['gateway']}");
        $output->writeln('  Reference:  ' . (string) ($intent['reference'] ?? '-'));
        $output->writeln("  Amount:     {$intent['amount']} {$intent['currency']}");
        $output->writeln('');
        $output->writeln(
            "Superseding marks this payment attempt abandoned in Thallo only. It does not cancel or refund "
            . "anything at {$intent['gateway']}: if the customer paid it, refund it there."
        );

        $outcome = $apply ? $retirement->retire($uuid, $tenant) : $retirement->outcomeFor($intent, $tenant);
        $status = (string) ($retirement->find($uuid)['status'] ?? $intent['status']);

        return match ($outcome) {
            IntentRetirement::RETIRED => $this->say($output, "Superseded {$uuid}.", self::SUCCESS),
            IntentRetirement::ALREADY => $this->say(
                $output,
                "{$uuid} is already superseded; nothing to do.",
                self::SUCCESS,
            ),
            IntentRetirement::FOREIGN => $this->say(
                $output,
                "<error>{$uuid} belongs to another workspace; this command reaches only unassigned intents "
                . "and the default workspace's. Nothing was changed.</error>",
                self::FAILURE,
            ),
            IntentRetirement::INACTIVE => $this->say(
                $output,
                "<error>Not superseded: {$uuid} is {$status}"
                . ($status === 'closed' ? ', so it may have been paid' : '')
                . '. Nothing was changed.</error>',
                self::FAILURE,
            ),
            default => $this->say(
                $output,
                'Dry run: nothing was changed. Run it again with --apply to supersede it.',
                self::SUCCESS,
            ),
        };
    }

    private function say(OutputInterface $output, string $message, int $status): int
    {
        $output->writeln($message);

        return $status;
    }

    /**
     * The move, with the adoption gate closed through its commit or rollback: webhook work that read a
     * payment's owner before the move holds the gate until its unit of work ends, so the repair waits
     * for its write instead of moving the row out from under it; work arriving during the move is
     * refused and retried after it.
     */
    private function applyBehindTheGate(PaymentTenancyAdoption $adoption, string $tenant): PaymentAdoptionReport
    {
        $gate = $this->gate ??= $this->getContainer()->get(AdoptionGate::class);
        $gate->acquireExclusive($this->gateTimeoutMs);
        try {
            return $adoption->apply($tenant);
        } finally {
            $gate->releaseExclusive();
        }
    }

    private function render(OutputInterface $output, PaymentAdoptionReport $report): void
    {
        $output->writeln("Payments without a workspace — default workspace {$report->tenantUuid}");
        $output->writeln('');
        $output->writeln(sprintf('  %-38s %10s %8s %6s  %s', 'Table', 'Unassigned', 'Default', 'Other', 'Guarded'));
        foreach ($report->counts as $table => $count) {
            $output->writeln(sprintf(
                '  %-38s %10d %8d %6d  %s',
                $table,
                $count['unassigned'],
                $count['default'],
                $count['other'],
                in_array($table, $report->constrained, true) ? 'yes' : 'no',
            ));
        }

        if ($report->collisions !== []) {
            $output->writeln('');
            $output->writeln('Keys the default workspace already holds (each refuses the move):');
            foreach ($report->collisions as $c) {
                $key = implode(', ', array_map(
                    static fn (string $column, mixed $value): string => $column . '=' . (string) $value,
                    array_keys($c['key']),
                    $c['key'],
                ));
                $output->writeln(
                    "  {$c['table']} #{$c['unassigned_id']}: {$key} — already held by #{$c['existing_id']}"
                );
            }
        }

        if ($report->ambiguous !== []) {
            $output->writeln('');
            $output->writeln('Unassigned rows another workspace owns (each refuses the move):');
            foreach ($report->ambiguous as $a) {
                $output->writeln("  {$a['table']} #{$a['id']} ({$a['uuid']}): {$a['reason']} ({$a['workspace']})");
            }
        }

        if ($report->duplicates !== []) {
            $output->writeln('');
            $output->writeln(
                'Orders with more than one payment intent — reconcile these with the gateway; moving rows '
                . 'does not resolve them:'
            );
            foreach ($report->duplicates as $d) {
                $output->writeln(sprintf(
                    '  %s %s — %d intent(s), %d settled payment(s)',
                    $d['payable_type'],
                    $d['payable_id'],
                    count($d['intents']),
                    $d['settled_payments'],
                ));
                foreach ($d['intents'] as $intent) {
                    $output->writeln(sprintf(
                        '    %s  %-12s %-10s %s  %s %s%s',
                        $intent['uuid'],
                        $intent['status'],
                        $intent['gateway'],
                        (string) ($intent['reference'] ?? '-'),
                        (string) $intent['amount'],
                        (string) $intent['currency'],
                        $intent['tenant_uuid'] === '' ? '  (unassigned)' : '',
                    ));
                }
            }
            $output->writeln(
                '  Once you know which attempt the customer did not pay, supersede it with '
                . '--retire-intent=<uuid> (add --apply). That does not cancel anything at the provider.'
            );
        }
        $output->writeln('');
    }
}

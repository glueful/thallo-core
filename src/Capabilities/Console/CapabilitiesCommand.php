<?php

declare(strict_types=1);

namespace Thallo\Core\Capabilities\Console;

use Glueful\Console\BaseCommand;
use Glueful\Routing\RouteCache;
use Glueful\Routing\RouteManifest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Core\Capabilities\CapabilityStateStore;

/**
 * The capability switchboard from a shell, as Extensions › Capabilities shows it: each capability
 * with what was asked for, whether its engine can back it, and whether it is on. `--enable` and
 * `--disable` flip the requested state under the admin's rule: disabling is always allowed,
 * enabling is refused while the engine cannot back it. A flip takes effect on the next request,
 * and clears the compiled routes because capabilities decide which routes exist.
 */
#[AsCommand(name: 'thallo:capabilities', description: 'List capabilities, or turn one on or off')]
final class CapabilitiesCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('enable', null, InputOption::VALUE_REQUIRED, 'Turn this capability on');
        $this->addOption('disable', null, InputOption::VALUE_REQUIRED, 'Turn this capability off');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the list as JSON, for scripts');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $enable = trim((string) ($input->getOption('enable') ?? ''));
        $disable = trim((string) ($input->getOption('disable') ?? ''));
        if ($enable !== '' && $disable !== '') {
            $this->error('Pass --enable or --disable, not both.');
            return self::FAILURE;
        }
        if ($enable !== '' || $disable !== '') {
            return $this->flip($enable !== '' ? $enable : $disable, $enable !== '');
        }

        $rows = $this->rows();
        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }
        (new Table($output))
            ->setHeaders(['Capability', 'Label', 'Requested', 'Available', 'Effective'])
            ->setRows(array_map(static fn(array $r): array => [
                $r['id'],
                $r['label'] ?? '—',
                $r['requested'] ? 'on' : 'off',
                $r['available'] ? 'yes' : 'no — ' . ($r['reason'] ?? 'engine unavailable'),
                $r['effective'] ? 'on' : 'off',
            ], $rows))
            ->render();

        return self::SUCCESS;
    }

    private function flip(string $id, bool $enabled): int
    {
        $registry = $this->getService(CapabilityRegistry::class);
        $known = array_map(static fn($c): string => $c->id, $registry->all());
        if (!in_array($id, $known, true)) {
            $this->error("No registered capability named {$id}. Run thallo:capabilities to list them.");
            return self::FAILURE;
        }
        $availability = $registry->availability($id);
        if ($enabled && !$availability->available) {
            $this->error("Cannot enable {$id}: " . ($availability->reason ?? 'its owning engine is unavailable.'));
            if ($availability->remedy !== null) {
                $this->line($availability->remedy);
            }
            return self::FAILURE;
        }

        $before = $registry->isEnabled($id);
        $this->getService(CapabilityStateStore::class)->put($id, $enabled);
        if (($enabled && $availability->available) !== $before) {
            (new RouteCache($this->getContext()))->clear();
            RouteManifest::reset();
        }
        $this->success(sprintf(
            '%s %s. It takes effect on the next request; restart long-running workers to pick it up.',
            $id,
            $enabled ? 'enabled' : 'disabled',
        ));

        return self::SUCCESS;
    }

    /** @return list<array<string,mixed>> */
    private function rows(): array
    {
        $registry = $this->getService(CapabilityRegistry::class);
        $rows = [];
        foreach ($registry->all() as $capability) {
            $availability = $registry->availability($capability->id);
            $rows[] = [
                'id' => $capability->id,
                'label' => $capability->label,
                'owning_package' => $capability->owningPackage,
                'requested' => $registry->isRequestedEnabled($capability->id),
                'available' => $availability->available,
                'reason' => $availability->reason,
                'effective' => $registry->isEnabled($capability->id),
            ];
        }
        usort($rows, static fn(array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));

        return $rows;
    }
}

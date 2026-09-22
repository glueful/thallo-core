<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Console;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Thallo\Core\Content\Blocks\BlockTypeRepository;
use Thallo\Tenancy\System\SystemFlags;
use Glueful\Extensions\Contracts\Tenancy\TenantContextRunner;

/** The block types an install (or a workspace) has, as Settings › Block Types lists them. */
#[AsCommand(name: 'thallo:blocks:list', description: 'List block types: slug, label, category, state, fields')]
final class ListBlockTypesCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('tenant', null, InputOption::VALUE_REQUIRED, 'Workspace uuid, when workspaces are on');
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Print the rows as JSON, for scripts');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tenant = trim((string) ($input->getOption('tenant') ?? ''));
        if ($this->getService(SystemFlags::class)->tenancyEnabled()) {
            if ($tenant === '') {
                $this->error('Workspaces are on: pass --tenant with the workspace uuid.');
                return self::FAILURE;
            }
            $rows = $this->getService(TenantContextRunner::class)->runAsTenant($tenant, fn(): array => $this->rows());
        } else {
            $rows = $this->rows();
        }

        if ((bool) $input->getOption('json')) {
            $output->writeln((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        }
        (new Table($output))
            ->setHeaders(['Slug', 'Label', 'Category', 'State', 'Fields'])
            ->setRows(array_map(static fn(array $r): array => [
                $r['slug'],
                $r['label'],
                $r['category'] ?? '—',
                $r['active'] ? 'active' : 'inactive',
                $r['fields'] === [] ? '—' : implode(', ', $r['fields']),
            ], $rows))
            ->render();
        $this->line(sprintf('%d block type(s).', count($rows)));

        return self::SUCCESS;
    }

    /** @return list<array{slug:string,label:string,category:?string,active:bool,fields:list<string>}> */
    private function rows(): array
    {
        return array_map(static fn(array $type): array => [
            'slug' => (string) $type['slug'],
            'label' => (string) $type['label'],
            'category' => isset($type['category']) ? (string) $type['category'] : null,
            'active' => (bool) $type['active'],
            'fields' => array_values(array_map(
                static fn(array $f): string => (string) ($f['name'] ?? '') . ' (' . (string) ($f['type'] ?? '') . ')',
                array_filter((array) $type['schema'], 'is_array'),
            )),
        ], $this->getService(BlockTypeRepository::class)->all());
    }
}

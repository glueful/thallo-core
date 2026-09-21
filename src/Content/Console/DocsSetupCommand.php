<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Console;

use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Thallo\Core\Content\Docs\DocsSetup;

/** `thallo:docs:setup` — a docs section for this site, in one step ({@see DocsSetup}). */
#[AsCommand(
    name: 'thallo:docs:setup',
    description: 'Create the content type a documentation section needs, and let the site list it',
)]
final class DocsSetupCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this
            ->setHelp(
                "Makes a documentation section for this site: a content type whose pages are Markdown,\n"
                . "grouped into sections for the sidebar. The type's slug is the URL.\n\n"
                . "  thallo:docs:setup                          /docs, the default sections\n"
                . "  thallo:docs:setup --type=handbook          /handbook\n"
                . "  thallo:docs:setup --sections=start,guides  your own sections, in sidebar order\n\n"
                . "Then import a folder of Markdown:\n"
                . "  thallo:import:markdown docs --type=docs --publish\n\n"
                . 'Safe to run again: an existing type is never rewritten.'
            )
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'The content type slug, which is the URL', 'docs')
            ->addOption(
                'sections',
                null,
                InputOption::VALUE_REQUIRED,
                'Comma-separated sections, in sidebar order',
                implode(',', DocsSetup::DEFAULT_SECTIONS),
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slug = (string) $input->getOption('type');
        $sections = array_map(trim(...), explode(',', (string) $input->getOption('sections')));
        /** @var DocsSetup $setup */
        $setup = $this->getService(DocsSetup::class);
        try {
            $result = $setup->run($slug, $sections);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        if ($result['missing'] !== []) {
            $this->error(
                "A content type \"{$slug}\" already exists without: " . implode(', ', $result['missing'])
                . '. Add those fields, or choose another --type.',
            );
            return self::FAILURE;
        }
        $this->success($result['created']
            ? "Created the \"{$slug}\" content type."
            : "The \"{$slug}\" content type is already set up.");
        if ($result['listed']) {
            $this->line("Listed it, so /{$slug} is the section's index.");
        }
        $this->line("Next: php glueful thallo:import:markdown <folder> --type={$slug} --publish");
        return self::SUCCESS;
    }
}

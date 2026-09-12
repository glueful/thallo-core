<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Console;

use Thallo\Core\Content\Authorization\PolicyManifest;
use Glueful\Console\BaseCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'thallo:policy:manifest', description: 'Export or validate workspace role policy manifests.')]
final class PolicyManifestCommand extends BaseCommand
{
    protected function configure(): void
    {
        $this->addOption('export', null, InputOption::VALUE_NONE, 'Export the deployed policy manifest.')
            ->addOption('validate', null, InputOption::VALUE_REQUIRED, 'Validate a policy manifest file.')
            ->addOption(
                'compare',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Compare two policy manifest files: --compare old.json --compare new.json.',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $manifest = $this->getService(PolicyManifest::class);
        if ((bool) $input->getOption('export')) {
            $this->line(json_encode($manifest->export($this->context), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
            return self::SUCCESS;
        }
        $validate = $input->getOption('validate');
        if (is_string($validate) && $validate !== '') {
            $errors = $manifest->validate($this->readManifest($validate));
            foreach ($errors as $error) {
                $this->error($error);
            }
            return $errors === [] ? self::SUCCESS : self::FAILURE;
        }
        $compare = $input->getOption('compare');
        if (is_array($compare) && count($compare) === 2) {
            try {
                $this->line(json_encode(
                    $manifest->compare($this->readManifest($compare[0]), $this->readManifest($compare[1])),
                    JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR,
                ));
                return self::SUCCESS;
            } catch (\InvalidArgumentException $exception) {
                $this->error($exception->getMessage());
                return self::FAILURE;
            }
        }
        $this->error('Choose --export, --validate <file>, or two --compare <file> options.');
        return self::FAILURE;
    }

    /** @return array<string,mixed> */
    private function readManifest(string $path): array
    {
        $json = @file_get_contents($path);
        if ($json === false) {
            throw new \InvalidArgumentException("Cannot read policy manifest: {$path}");
        }
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException("Policy manifest is not an object: {$path}");
        }
        return $decoded;
    }
}

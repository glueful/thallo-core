<?php

declare(strict_types=1);

namespace Thallo\Core\Setup;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Installer\Installer;
use Glueful\Installer\InstallOptions;
use Glueful\Installer\InstallResult;

/** The framework Installer, as provision runs it. */
final class FrameworkProvisionInstaller implements ProvisionInstaller
{
    public function run(string $basePath, ApplicationContext $context, InstallOptions $options): InstallResult
    {
        return (new Installer($basePath, $context))->run($options);
    }
}

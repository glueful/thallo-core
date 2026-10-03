<?php

declare(strict_types=1);

namespace Thallo\Core\Setup;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Installer\InstallOptions;
use Glueful\Installer\InstallResult;

/** Provision's installer step (connection test, .env, keys, migrations), injectable for tests. */
interface ProvisionInstaller
{
    public function run(string $basePath, ApplicationContext $context, InstallOptions $options): InstallResult;
}

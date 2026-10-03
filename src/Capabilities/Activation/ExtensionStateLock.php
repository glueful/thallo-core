<?php

declare(strict_types=1);

namespace Thallo\Core\Capabilities\Activation;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\ExtensionStateMutex;
use Thallo\Contracts\Extensions\ExtensionStateCoordinator;

/**
 * Thallo's writers of the enabled-provider list (an activation's engine step, provision's
 * required-provider repair, tenancy's enable and disable) take the framework's own extension-state
 * lock, the one its extension commands and schema executor take: one lock for every change to the
 * list, whoever makes it. It is a file lock, re-entrant within a process, so a sequence that
 * reaches the framework's lock again doesn't wait on itself.
 */
final class ExtensionStateLock implements ExtensionStateCoordinator
{
    public function __construct(private readonly ApplicationContext $context)
    {
    }

    public function within(callable $sequence): mixed
    {
        return ExtensionStateMutex::within($this->context, $sequence);
    }
}

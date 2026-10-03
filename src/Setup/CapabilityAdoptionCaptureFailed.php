<?php

declare(strict_types=1);

namespace Thallo\Core\Setup;

/** Provision couldn't inspect an existing database before migrating it, so it doesn't migrate. */
final class CapabilityAdoptionCaptureFailed extends \RuntimeException
{
}

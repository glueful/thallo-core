<?php

declare(strict_types=1);

namespace Thallo\Core\Capabilities\Activation;

/**
 * Where one ActivationRunner::run() stopped: the record as it now stands, and whether the next
 * step needs a context booted after the engine step (the admin continues, the CLI starts a child).
 */
final class ActivationOutcome
{
    public function __construct(public readonly ActivationRecord $record, public readonly bool $needsBoot)
    {
    }
}

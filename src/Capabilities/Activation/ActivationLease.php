<?php

declare(strict_types=1);

namespace Thallo\Core\Capabilities\Activation;

/** A runner's claim on one generation of an activation: every write it makes is fenced on both. */
final class ActivationLease
{
    public function __construct(
        public readonly string $capability,
        public readonly int $generation,
        public readonly string $ownerToken,
    ) {
    }
}

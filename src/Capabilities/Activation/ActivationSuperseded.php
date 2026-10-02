<?php

declare(strict_types=1);

namespace Thallo\Core\Capabilities\Activation;

/** A fenced write found a newer decision: another generation, or another lease owner. */
final class ActivationSuperseded extends \RuntimeException
{
}

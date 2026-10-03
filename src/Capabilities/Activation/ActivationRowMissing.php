<?php

declare(strict_types=1);

namespace Thallo\Core\Capabilities\Activation;

/** An activation row was locked before ActivationStore::initializeRow() created it. */
final class ActivationRowMissing extends \RuntimeException
{
}

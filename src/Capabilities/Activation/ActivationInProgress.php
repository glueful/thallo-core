<?php

declare(strict_types=1);

namespace Thallo\Core\Capabilities\Activation;

/** Another runner holds the operation's live lease: this continuation runs nothing. */
final class ActivationInProgress extends \RuntimeException
{
}

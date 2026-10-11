<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

final class UpdateRefused extends \RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}

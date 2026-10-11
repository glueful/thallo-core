<?php

declare(strict_types=1);

namespace Thallo\Core\Updater;

/** A test kill point fired (Runner::killPoint): rethrown past every step's catch as the original exception. */
final class Killed extends \RuntimeException
{
    public function __construct(public readonly \Throwable $cause)
    {
        parent::__construct($cause->getMessage());
    }
}

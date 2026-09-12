<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Authorization;

final class RoleOverrideException extends \InvalidArgumentException
{
    /** @param array<string,string> $errors */
    public function __construct(string $message, public readonly array $errors = [])
    {
        parent::__construct($message);
    }
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Validation;

use Thallo\Contracts\Authoring\ValidationFailed;

final class ValidationException extends \RuntimeException implements ValidationFailed
{
    /** @param array<string,string> $errors */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('field validation failed');
    }

    /** @return array<string,string> */
    public function errors(): array
    {
        return $this->errors;
    }
}

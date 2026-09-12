<?php

declare(strict_types=1);

namespace Thallo\Core\Signup;

final class SignupException extends \RuntimeException
{
    /** @param array<string,string> $errors */
    public function __construct(
        string $message,
        public readonly int $status = 422,
        public readonly array $errors = [],
        public readonly ?string $errorCode = null,
    ) {
        parent::__construct($message);
    }
}

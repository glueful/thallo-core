<?php

declare(strict_types=1);

namespace Thallo\Core\Support;

/**
 * Raised by {@see UserRoleAssignmentPolicy} when a role-assignment request is not permitted.
 * Carries the HTTP status the controller should return: 403 for an authorization/ceiling denial,
 * 422 for an invalid (unknown) role slug.
 */
final class RoleAssignmentException extends \RuntimeException
{
    private function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }

    public static function forbidden(string $message): self
    {
        return new self(403, $message);
    }

    public static function unprocessable(string $message): self
    {
        return new self(422, $message);
    }
}

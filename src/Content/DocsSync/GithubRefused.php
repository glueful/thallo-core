<?php

declare(strict_types=1);

namespace Thallo\Core\Content\DocsSync;

/**
 * GitHub said no, or could not be reached, in words an owner can act on (docs sync design §5).
 * `transient`: worth another attempt (cut short, unreachable); otherwise the answer stands
 * (missing, private, too large, not a zip).
 */
final class GithubRefused extends \RuntimeException
{
    public function __construct(string $message, public readonly bool $transient = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}

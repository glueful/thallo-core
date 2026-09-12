<?php

declare(strict_types=1);

namespace Thallo\Core\Support;

/** Internal violation carried out of a rolled-back authority transaction. */
final class AuthorityContinuityViolation extends \RuntimeException
{
    public function __construct(
        public readonly ?string $actorUuid,
        public readonly string $targetUuid,
        public readonly string $operation,
        public readonly string $reason,
    ) {
        parent::__construct('This change would remove the last holder of a required authority.');
    }
}

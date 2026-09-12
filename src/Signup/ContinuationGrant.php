<?php

declare(strict_types=1);

namespace Thallo\Core\Signup;

final readonly class ContinuationGrant
{
    /** @param array<string,mixed>|null $result */
    public function __construct(
        public string $token,
        public bool $replay,
        public bool $resume,
        public ?array $result = null,
    ) {
    }
}

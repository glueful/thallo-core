<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Forms\Spam;

/**
 * The outcome of a spam-guard check (form-block spec §8). A reject carries a machine
 * reason ('honeypot'|'time_trap'|'rate_limit') recorded server-side ONLY — the visitor
 * always sees a generic response so a bot learns nothing about which trap fired.
 */
final class GuardResult
{
    private function __construct(
        private readonly bool $passed,
        private readonly ?string $reason,
    ) {
    }

    public static function pass(): self
    {
        return new self(true, null);
    }

    public static function reject(string $reason): self
    {
        return new self(false, $reason);
    }

    public function passed(): bool
    {
        return $this->passed;
    }

    public function reason(): ?string
    {
        return $this->reason;
    }
}

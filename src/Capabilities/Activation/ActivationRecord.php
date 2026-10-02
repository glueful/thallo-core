<?php

declare(strict_types=1);

namespace Thallo\Core\Capabilities\Activation;

/** A read-only snapshot of one capability_activations row. */
final class ActivationRecord
{
    /**
     * @param list<string> $stepsDone
     * @param array<string, string> $workspaces tenant uuid => ready|failed
     * @param array<string, mixed> $result
     */
    public function __construct(
        public readonly string $capability,
        public readonly int $generation,
        public readonly string $status,
        public readonly array $stepsDone,
        public readonly ?string $failedStep,
        public readonly ?string $error,
        public readonly ?string $remedy,
        public readonly ?string $ownerToken,
        public readonly ?string $leaseExpiresAt,
        public readonly array $workspaces,
        public readonly array $result,
        public readonly string $actor,
        public readonly string $updatedAt,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $json = static fn (mixed $value, array $default): array => is_string($value)
            ? (is_array($decoded = json_decode($value, true)) ? $decoded : $default)
            : $default;
        return new self(
            (string) $row['capability'],
            (int) $row['generation'],
            (string) $row['status'],
            array_values(array_map('strval', $json($row['steps_done'] ?? null, []))),
            isset($row['failed_step']) ? (string) $row['failed_step'] : null,
            isset($row['error']) ? (string) $row['error'] : null,
            isset($row['remedy']) ? (string) $row['remedy'] : null,
            isset($row['owner_token']) ? (string) $row['owner_token'] : null,
            isset($row['lease_expires_at']) ? (string) $row['lease_expires_at'] : null,
            array_map('strval', $json($row['workspaces'] ?? null, [])),
            $json($row['result'] ?? null, []),
            (string) ($row['actor'] ?? ''),
            (string) ($row['updated_at'] ?? ''),
        );
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ActivationStatus::OPEN, true);
    }

    /** The first step not done yet, or null when every step is done. */
    public function nextStep(): ?string
    {
        foreach (ActivationStep::ALL as $step) {
            if (!in_array($step, $this->stepsDone, true)) {
                return $step;
            }
        }
        return null;
    }

    /** @return array<string, mixed> the API shape: snake_case, never the owner token */
    public function toArray(): array
    {
        return [
            'capability' => $this->capability,
            'generation' => $this->generation,
            'status' => $this->status,
            'steps_done' => $this->stepsDone,
            'next_step' => $this->nextStep(),
            'failed_step' => $this->failedStep,
            'error' => $this->error,
            'remedy' => $this->remedy,
            'workspaces' => (object) $this->workspaces,
            'result' => (object) $this->result,
            'actor' => $this->actor,
            'updated_at' => $this->updatedAt,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Forms;

/**
 * A stored form submission (form-block spec §6/§7). `fieldsSnapshot` is the sealed
 * field list the visitor actually saw (so the admin renders the submission exactly as
 * it was collected, even after the block config changes); `values` are the normalized
 * answers keyed by field key.
 */
final class FormSubmission
{
    /**
     * @param list<array<string,mixed>> $fieldsSnapshot
     * @param array<string,mixed> $values
     */
    public function __construct(
        public readonly string $uuid,
        public readonly string $formKey,
        public readonly string $formName,
        public readonly ?string $sourceUrl,
        public readonly array $fieldsSnapshot,
        public readonly array $values,
        public readonly int $descriptorVersion,
        public readonly string $status,
        public readonly ?string $ip,
        public readonly ?string $userAgent,
        public readonly string $submittedAt,
    ) {
    }

    /** @param array<string,mixed> $row a hydrated DB row (JSON columns already decoded) */
    public static function fromRow(array $row): self
    {
        return new self(
            uuid: (string) $row['uuid'],
            formKey: (string) $row['form_key'],
            formName: (string) $row['form_name'],
            sourceUrl: isset($row['source_url']) ? (string) $row['source_url'] : null,
            fieldsSnapshot: is_array($row['fields_snapshot'] ?? null) ? $row['fields_snapshot'] : [],
            values: is_array($row['submitted_values'] ?? null) ? $row['submitted_values'] : [],
            descriptorVersion: (int) ($row['descriptor_version'] ?? 1),
            status: (string) ($row['status'] ?? 'unread'),
            ip: isset($row['ip']) ? (string) $row['ip'] : null,
            userAgent: isset($row['user_agent']) ? (string) $row['user_agent'] : null,
            submittedAt: (string) ($row['submitted_at'] ?? ''),
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'uuid' => $this->uuid,
            'form_key' => $this->formKey,
            'form_name' => $this->formName,
            'source_url' => $this->sourceUrl,
            'fields_snapshot' => $this->fieldsSnapshot,
            'values' => $this->values,
            'descriptor_version' => $this->descriptorVersion,
            'status' => $this->status,
            'ip' => $this->ip,
            'user_agent' => $this->userAgent,
            'submitted_at' => $this->submittedAt,
        ];
    }
}

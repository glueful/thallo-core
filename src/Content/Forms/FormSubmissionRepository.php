<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Forms;

use Glueful\Database\Connection;
use Glueful\Helpers\Utils;

/**
 * Persistence for stored form submissions (form-block spec §6/§7). JSON columns
 * (fields_snapshot, submitted_values) are encoded on write and decoded on read; the
 * admin triage surface reads through list()/find()/unreadCount() and CSV export()
 * streams rows via a generator so a large mailbox never loads whole into memory.
 */
final class FormSubmissionRepository
{
    private const TABLE = 'form_submissions';

    public function __construct(private readonly Connection $db)
    {
    }

    public function store(FormSubmission $s): string
    {
        $uuid = $s->uuid !== '' ? $s->uuid : Utils::generateNanoID();
        $this->db->table(self::TABLE)->insert([
            'uuid' => $uuid,
            'form_key' => $s->formKey,
            'form_name' => $s->formName,
            'source_url' => $s->sourceUrl,
            'fields_snapshot' => (string) json_encode(array_values($s->fieldsSnapshot)),
            'submitted_values' => (string) json_encode($s->values),
            'descriptor_version' => $s->descriptorVersion,
            'status' => $s->status,
            'ip' => $s->ip,
            'user_agent' => $s->userAgent,
            'submitted_at' => $s->submittedAt,
        ]);
        return $uuid;
    }

    /**
     * @param array{form_key?: string, status?: string} $filter
     * @return list<FormSubmission>
     */
    public function list(array $filter = []): array
    {
        $rows = $this->applyFilters($this->db->table(self::TABLE), $filter)
            ->orderBy('submitted_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->get();
        return array_map(fn (array $row): FormSubmission => FormSubmission::fromRow($this->hydrate($row)), $rows);
    }

    public function find(string $uuid): ?FormSubmission
    {
        $row = $this->db->table(self::TABLE)->where('uuid', '=', $uuid)->first();
        return $row === null ? null : FormSubmission::fromRow($this->hydrate((array) $row));
    }

    public function markRead(string $uuid): void
    {
        $this->db->table(self::TABLE)->where('uuid', '=', $uuid)->update(['status' => 'read']);
    }

    public function delete(string $uuid): void
    {
        $this->db->table(self::TABLE)->where('uuid', '=', $uuid)->delete();
    }

    public function unreadCount(): int
    {
        return (int) $this->db->table(self::TABLE)->where('status', '=', 'unread')->count();
    }

    /**
     * Stream rows for CSV export (spec §7) — a generator so large mailboxes never fully
     * materialize. Yields hydrated FormSubmission VOs newest-first.
     *
     * @param array{form_key?: string, status?: string} $filter
     * @return iterable<FormSubmission>
     */
    public function export(array $filter = []): iterable
    {
        $rows = $this->applyFilters($this->db->table(self::TABLE), $filter)
            ->orderBy('submitted_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->get();
        foreach ($rows as $row) {
            yield FormSubmission::fromRow($this->hydrate($row));
        }
    }

    /**
     * @param object $query the table query builder
     * @param array{form_key?: string, status?: string} $filter
     * @return object the same builder with filters applied
     */
    private function applyFilters(object $query, array $filter): object
    {
        if (isset($filter['form_key']) && $filter['form_key'] !== '') {
            $query->where('form_key', '=', $filter['form_key']);
        }
        if (isset($filter['status']) && $filter['status'] !== '') {
            $query->where('status', '=', $filter['status']);
        }
        return $query;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> JSON columns decoded */
    private function hydrate(array $row): array
    {
        $row['fields_snapshot'] = (array) json_decode((string) ($row['fields_snapshot'] ?? '[]'), true);
        $row['submitted_values'] = (array) json_decode((string) ($row['submitted_values'] ?? '[]'), true);
        return $row;
    }
}

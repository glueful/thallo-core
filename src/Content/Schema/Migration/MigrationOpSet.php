<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Schema\Migration;

final class MigrationOpSet
{
    /** @var list<MigrationOp> */
    private array $ops;

    /** @param list<MigrationOp> $ops */
    public function __construct(array $ops)
    {
        if ($ops === []) {
            throw new \InvalidArgumentException('MigrationOpSet requires at least one operation.');
        }
        foreach ($ops as $op) {
            if (!$op instanceof MigrationOp) {
                throw new \InvalidArgumentException('MigrationOpSet only accepts MigrationOp instances.');
            }
        }
        $this->ops = array_values($ops);
    }

    /**
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    public function apply(array $fields): array
    {
        foreach ($this->ops as $op) {
            $fields = $op->apply($fields);
        }

        return $fields;
    }

    /**
     * @param array<string,mixed> $fields
     * @return array<string,mixed>
     */
    public function applyForProjection(array $fields): array
    {
        foreach ($this->ops as $op) {
            $fields = $op->applyForProjection($fields);
        }

        return $fields;
    }

    /** @return list<array<string,string>> */
    public function toArray(): array
    {
        return array_map(
            static fn (MigrationOp $op): array => $op->toArray(),
            $this->ops
        );
    }

    /**
     * @param list<array<string,mixed>> $data
     */
    public static function fromArray(array $data): self
    {
        $ops = [];
        foreach ($data as $raw) {
            $kind = (string) ($raw['op'] ?? '');
            if ($kind === 'rename') {
                $ops[] = new RenameField((string) ($raw['from'] ?? ''), (string) ($raw['to'] ?? ''));
                continue;
            }
            if ($kind === 'delete') {
                $ops[] = new DeleteField((string) ($raw['name'] ?? ''));
                continue;
            }
            throw new \InvalidArgumentException("Unknown schema migration op '{$kind}'.");
        }

        return new self($ops);
    }
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Schema\Migration;

final class DeleteField implements MigrationOp
{
    public function __construct(public readonly string $name)
    {
        if ($name === '') {
            throw new \InvalidArgumentException('DeleteField requires a non-empty field name.');
        }
    }

    public function apply(array $fields): array
    {
        unset($fields[$this->name]);

        return $fields;
    }

    public function applyForProjection(array $fields): array
    {
        return $this->apply($fields);
    }

    public function toArray(): array
    {
        return ['op' => 'delete', 'name' => $this->name];
    }
}

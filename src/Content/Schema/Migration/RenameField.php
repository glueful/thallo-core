<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Schema\Migration;

final class RenameField implements MigrationOp
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
    ) {
        if ($from === '' || $to === '' || $from === $to) {
            throw new \InvalidArgumentException('RenameField requires distinct non-empty source and target names.');
        }
    }

    public function apply(array $fields): array
    {
        if (!array_key_exists($this->from, $fields)) {
            return $fields;
        }
        if (array_key_exists($this->to, $fields)) {
            throw new MigrationCollisionException(
                "Cannot rename '{$this->from}' to '{$this->to}': target already exists."
            );
        }

        $fields[$this->to] = $fields[$this->from];
        unset($fields[$this->from]);

        return $fields;
    }

    public function applyForProjection(array $fields): array
    {
        if (!array_key_exists($this->from, $fields)) {
            return $fields;
        }
        if (!array_key_exists($this->to, $fields)) {
            $fields[$this->to] = $fields[$this->from];
        }
        unset($fields[$this->from]);

        return $fields;
    }

    public function toArray(): array
    {
        return ['op' => 'rename', 'from' => $this->from, 'to' => $this->to];
    }
}

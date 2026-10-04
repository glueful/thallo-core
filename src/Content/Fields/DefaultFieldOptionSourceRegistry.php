<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Fields;

use Thallo\Contracts\Fields\FieldOptionSource;
use Thallo\Contracts\Fields\FieldOptionSourceRegistry;

/** The sources of server-provided field choices, by id (search block spec §3.9). */
final class DefaultFieldOptionSourceRegistry implements FieldOptionSourceRegistry
{
    /** @var array<string, FieldOptionSource> */
    private array $sources = [];

    public function register(FieldOptionSource $source): void
    {
        $id = $source->id();
        if (isset($this->sources[$id])) {
            throw new \LogicException("Field option source '{$id}' is already registered.");
        }
        $this->sources[$id] = $source;
    }

    public function find(string $id): ?FieldOptionSource
    {
        return $this->sources[$id] ?? null;
    }
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Starter;

interface StarterKind
{
    public function kind(): string;

    /** @return list<StarterDefinition> */
    public function definitions(): array;

    public function fingerprint(StarterDefinition $definition): string;

    /** @return array{key:string,fingerprint:string}|null */
    public function locateExact(string $definitionKey): ?array;

    /** @return array{key:string,fingerprint:string}|null */
    public function locateForAdoption(StarterDefinition $definition): ?array;

    public function apply(StarterDefinition $definition, SeedContext $seed): StarterApplyResult;

    public function updateTo(
        StarterDefinition $definition,
        string $rowKey,
        SeedContext $seed,
    ): void;

    public function rename(StarterDefinition $definition, string $oldKey): void;

    public function syncable(): bool;
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Starter;

final class StarterDefinitions
{
    /** @var list<StarterKind> */
    private array $kinds;

    public function __construct(StarterKind ...$kinds)
    {
        $this->kinds = array_values($kinds);
    }

    /** @return list<StarterKind> */
    public function kinds(): array
    {
        return $this->kinds;
    }

    /** @return list<StarterKind> */
    public function syncKinds(): array
    {
        return array_values(array_filter(
            $this->kinds,
            static fn(StarterKind $kind): bool => $kind->syncable(),
        ));
    }
}

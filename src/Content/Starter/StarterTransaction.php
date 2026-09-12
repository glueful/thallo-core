<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Starter;

use Glueful\Database\Connection;

final class StarterTransaction
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function run(callable $fn): mixed
    {
        $baseline = $this->connection->transactionLevel();
        try {
            return $this->connection->transaction($fn);
        } catch (\Throwable $e) {
            while ($this->connection->transactionLevel() > $baseline) {
                $this->connection->getTransactionManager()->rollback();
            }
            throw $e;
        }
    }

    public function afterCommit(callable $effect): void
    {
        $this->connection->afterCommit($effect);
    }
}

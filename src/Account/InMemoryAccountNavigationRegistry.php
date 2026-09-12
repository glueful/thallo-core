<?php

declare(strict_types=1);

namespace Thallo\Core\Account;

use Thallo\Contracts\Account\AccountNavigationItem;
use Thallo\Contracts\Account\AccountNavigationRegistry;

/**
 * Per-request account-navigation registry. Packs register their sections during boot; the account
 * shell reads them back ordered. Shared (one instance per container), so registrations from every
 * pack accumulate into one list.
 */
final class InMemoryAccountNavigationRegistry implements AccountNavigationRegistry
{
    /** @var list<AccountNavigationItem> */
    private array $items = [];

    public function register(AccountNavigationItem $item): void
    {
        $this->items[] = $item;
    }

    /** @return list<AccountNavigationItem> */
    public function items(): array
    {
        $items = $this->items;
        usort($items, static fn (AccountNavigationItem $a, AccountNavigationItem $b): int => $a->order <=> $b->order);

        return $items;
    }
}

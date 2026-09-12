<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Authoring;

use Thallo\Core\Content\Repositories\EntryRepository;
use Thallo\Contracts\Content\EntryExistenceReader;

/**
 * Engine-backed {@see EntryExistenceReader} over `entries` (via {@see EntryRepository}).
 *
 * Tenant validation is belt-and-suspenders, deliberately not relying on a single mechanism:
 * `EntryRepository::findEntry()` is a plain query-builder read, so it is auto-scoped to the
 * ambient tenant context by the tenancy extension's table hook whenever that hook is
 * registered (enforcement active -- see `glueful/tenancy`'s `TenancyServiceProvider::
 * registerTableHook()`). But that hook is not necessarily active in every one of Thallo's three
 * resolution modes (design spec §4.1) or before the `entries` table has been retrofitted with a
 * `tenant_uuid` column, so this ALSO checks the row's own `tenant_uuid` explicitly whenever the
 * column is present on the returned row, rather than depending solely on the ambient hook
 * having applied to this particular read.
 */
final class EngineEntryExistenceReader implements EntryExistenceReader
{
    public function __construct(private readonly EntryRepository $entries)
    {
    }

    public function exists(string $entryUuid, string $tenant): ?array
    {
        $entry = $this->entries->findEntry($entryUuid);
        if ($entry === null || ($entry['status'] ?? null) === 'deleted') {
            return null;
        }

        if (array_key_exists('tenant_uuid', $entry) && (string) $entry['tenant_uuid'] !== $tenant) {
            return null;
        }

        return [
            'uuid' => (string) $entry['uuid'],
            'content_type_uuid' => (string) $entry['content_type_uuid'],
        ];
    }
}

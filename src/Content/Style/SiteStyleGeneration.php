<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Style;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantScope;
use Thallo\Contracts\Tenancy\WriteBarrier;

/**
 * The site style generation (visual builder spec §4.3): the version of the site's style-class
 * definitions, one row per site in `style_generations`. Generation N names one exact set of
 * class records. It is incremented only by `StyleClassRepository::write()`, on the writing
 * transaction's own connection, as an atomic `generation = generation + 1` — so two concurrent
 * class writes serialise on the row and never write the same value — and read back on that
 * connection inside the transaction. Reads are never cached. Document rewrites never touch it.
 */
class SiteStyleGeneration
{
    public function __construct(
        private readonly Connection $db,
        private readonly ?ApplicationContext $context = null,
        private readonly ?CurrentTenantResolver $tenants = null,
        private readonly ?WriteBarrier $barrier = null,
    ) {
    }

    public function current(): int
    {
        return $this->read($this->db);
    }

    /**
     * Creates the site's row when it does not exist yet. Called OUTSIDE the write transaction:
     * two first writers can race here, the unique index refuses the second insert, and a failed
     * statement inside the write transaction would abort it on PostgreSQL.
     */
    public function ensureRow(): void
    {
        if ($this->db->table('style_generations')->select(['id'])->first() !== null) {
            return;
        }
        try {
            $this->db->table('style_generations')->insert([
                'site' => 'site',
                'generation' => 0,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            if ($this->db->table('style_generations')->select(['id'])->first() === null) {
                throw $e; // not the race: surface it
            }
        }
    }

    /** Runs on the caller's connection, inside the caller's transaction; returns the new value. */
    public function incrementWithin(Connection $db): int
    {
        if ($db->table('style_generations')->select(['id'])->first() === null) {
            throw new \LogicException('style_generations has no row for this site: call ensureRow() first');
        }
        $tenant = TenantScope::current($this->tenants, $this->context);
        $scope = $tenant === null ? '' : ' WHERE tenant_uuid = :tenant';
        $params = ['at' => gmdate('Y-m-d H:i:s')];
        if ($tenant !== null) {
            $params['tenant'] = $tenant;
        }
        $write = static function () use ($db, $scope, $params): bool {
            $stmt = $db->getPDO()->prepare(
                'UPDATE style_generations SET generation = generation + 1, updated_at = :at' . $scope
            );
            return $stmt->execute($params);
        };
        $this->barrier !== null ? $this->barrier->runWritable($write) : $write();
        return $this->read($db);
    }

    private function read(Connection $db): int
    {
        $row = $db->table('style_generations')->select(['generation'])->first();
        return $row === null ? 0 : (int) $row['generation'];
    }
}

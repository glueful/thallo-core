<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Builders\SchemaBuilder;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;
use Thallo\Core\Setup\CapabilityAdoption;

/**
 * The one-row record of the upgrade adoption (feature activation spec §7.3a). Provision's capture
 * creates the same table itself, before any migration runs; this declares it for the schema
 * history. Both take the same advisory lock around the DDL, so they never race.
 */
final class CreateCapabilityAdoptionTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema instanceof SchemaBuilder) {
            throw new \RuntimeException('The capability adoption migration requires the Glueful SchemaBuilder.');
        }
        $connection = $schema->getConnection();
        $connection->transaction(static function () use ($connection): void {
            $pdo = $connection->getPDO();
            $pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(?))')->execute([CapabilityAdoption::DDL_LOCK]);
            $pdo->exec(CapabilityAdoption::TABLE_DDL);
        });
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('thallo_capability_adoption');
    }

    public function getDescription(): string
    {
        return 'Create thallo_capability_adoption, the record of the one-time upgrade adoption.';
    }
}

<?php

declare(strict_types=1);

use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/** Tracks which tenant definitions still match Thallo's code-level starters. */
final class CreateStarterProvenanceTable implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if ($schema->hasTable('starter_provenance')) {
            return;
        }

        $schema->createTable('starter_provenance', function ($table): void {
            $table->bigInteger('id')->primary()->autoIncrement();
            $table->string('uuid', 12)->unique();
            $table->string('tenant_uuid', 12)->nullable();
            $table->string('definition_kind', 32);
            $table->string('definition_key', 255);
            $table->string('source_id', 255);
            $table->string('fingerprint', 64);
            $table->string('state', 16)->default('applied');
            $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
            $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');

            $table->index('tenant_uuid');
            $table->unique(
                ['tenant_uuid', 'definition_kind', 'definition_key'],
                'uniq_starter_provenance_key',
            );
            $table->unique(
                ['tenant_uuid', 'definition_kind', 'source_id'],
                'uniq_starter_provenance_source',
            );
        });

        if (!$schema->hasTable('thallo_system_flags')) {
            return;
        }

        $db = new Connection();
        $state = $db->table('thallo_system_flags')
            ->where('key', '=', 'tenancy.schema_state')
            ->first();
        if (($state['value'] ?? null) === 'widened' && $db->getDriverName() === 'pgsql') {
            $db->getPDO()->exec(
                'ALTER TABLE starter_provenance ALTER COLUMN tenant_uuid SET NOT NULL'
            );
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('starter_provenance');
    }

    public function getDescription(): string
    {
        return 'Create the tenant starter provenance ledger.';
    }
}

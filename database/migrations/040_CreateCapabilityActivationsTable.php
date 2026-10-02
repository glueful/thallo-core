<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Builders\SchemaBuilder;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Capability activations (feature activation spec §3.3): one row per capability that turns on
 * through the activation flow — its generation, status, steps done, lease, per-workspace readiness
 * and result — and an append-only event log. System tables (never tenant-scoped).
 *
 * The rows for the activation capabilities are created here, so workspace creation always has a
 * row to share-lock before any activation has started.
 */
final class CreateCapabilityActivationsTable implements MigrationInterface
{
    private const ACTIVATION_CAPABILITIES = ['thallo.commerce', 'thallo.subscriptions'];

    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema instanceof SchemaBuilder) {
            throw new \RuntimeException('The capability activations migration requires the Glueful SchemaBuilder.');
        }
        $pdo = $schema->getConnection()->getPDO();
        if (!$schema->hasTable('capability_activations')) {
            $pdo->exec(
                <<<'SQL'
                CREATE TABLE capability_activations (
                  capability varchar(64) PRIMARY KEY,
                  generation bigint NOT NULL DEFAULT 0,
                  status varchar(16) NOT NULL,
                  steps_done jsonb NOT NULL DEFAULT '[]'::jsonb,
                  failed_step varchar(32) NULL,
                  error text NULL,
                  remedy text NULL,
                  owner_token varchar(32) NULL,
                  lease_expires_at timestamptz NULL,
                  workspaces jsonb NOT NULL DEFAULT '{}'::jsonb,
                  result jsonb NOT NULL DEFAULT '{}'::jsonb,
                  actor varchar(120) NOT NULL DEFAULT '',
                  started_at timestamptz NULL,
                  updated_at timestamptz NOT NULL DEFAULT NOW()
                )
                SQL
            );
        }
        if (!$schema->hasTable('capability_activation_events')) {
            $pdo->exec(
                <<<'SQL'
                CREATE TABLE capability_activation_events (
                  id bigserial PRIMARY KEY,
                  capability varchar(64) NOT NULL,
                  generation bigint NOT NULL,
                  event varchar(32) NOT NULL,
                  detail jsonb NOT NULL DEFAULT '{}'::jsonb,
                  actor varchar(120) NOT NULL DEFAULT '',
                  created_at timestamptz NOT NULL DEFAULT NOW()
                )
                SQL
            );
            $pdo->exec(
                'CREATE INDEX idx_capability_activation_events_gen'
                . ' ON capability_activation_events (capability, generation)'
            );
        }
        $insert = $pdo->prepare(
            "INSERT INTO capability_activations (capability, status) VALUES (?, 'idle')"
            . ' ON CONFLICT (capability) DO NOTHING'
        );
        foreach (self::ACTIVATION_CAPABILITIES as $capability) {
            $insert->execute([$capability]);
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('capability_activation_events');
        $schema->dropTableIfExists('capability_activations');
    }

    public function getDescription(): string
    {
        return 'Create capability_activations (one row per activation capability) and its event log.';
    }
}

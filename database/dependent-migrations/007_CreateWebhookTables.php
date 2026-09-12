<?php

declare(strict_types=1);

use Glueful\Database\Migrations\MigrationInterface;
use Glueful\Database\Schema\Interfaces\SchemaBuilderInterface;

/**
 * Materialize the framework's webhook tables so the admin API works before the first dispatch.
 *
 * The framework's WebhookDispatcher lazily creates `webhook_subscriptions` / `webhook_deliveries`
 * on its first dispatch() (DatabaseLogHandler-style auto-migration), so on a fresh instance — one
 * where no content event has fired yet — the Developers › Webhooks page would query tables that
 * don't exist. This migration creates them up front with the SAME schema; both sides guard on
 * hasTable(), so whichever runs first wins and the other is a no-op (no drift, no conflict).
 *
 * Schema mirrors Glueful\Api\Webhooks\WebhookDispatcher::ensure{Subscriptions,Deliveries}Table().
 * Soft reference only (delivery.subscription_id → subscription.id); no cross-package FK, matching
 * the media/audit sidecars' stance toward framework-owned schema.
 */
final class CreateWebhookTables implements MigrationInterface
{
    public function up(SchemaBuilderInterface $schema): void
    {
        if (!$schema->hasTable('webhook_subscriptions')) {
            $schema->createTable('webhook_subscriptions', function ($table) {
                $table->bigInteger('id')->unsigned()->primary()->autoIncrement();
                $table->string('uuid', 32)->unique();
                $table->string('url', 2048);
                $table->json('events');
                $table->string('secret', 255);
                $table->boolean('is_active')->default(true);
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');
                $table->timestamp('updated_at')->default('CURRENT_TIMESTAMP');

                $table->index('is_active');
            });
        }

        if (!$schema->hasTable('webhook_deliveries')) {
            $schema->createTable('webhook_deliveries', function ($table) {
                $table->bigInteger('id')->unsigned()->primary()->autoIncrement();
                $table->string('uuid', 32)->unique();
                $table->bigInteger('subscription_id')->unsigned();
                $table->string('event', 255);
                $table->json('payload');
                $table->string('status', 20)->default('pending');
                $table->integer('attempts')->unsigned()->default(0);
                $table->integer('response_code')->unsigned()->nullable();
                $table->text('response_body')->nullable();
                $table->timestamp('delivered_at')->nullable();
                $table->timestamp('next_retry_at')->nullable();
                $table->timestamp('created_at')->default('CURRENT_TIMESTAMP');

                $table->index('subscription_id');
                $table->index('status');
                $table->index('next_retry_at');
            });
        }
    }

    public function down(SchemaBuilderInterface $schema): void
    {
        $schema->dropTableIfExists('webhook_deliveries');
        $schema->dropTableIfExists('webhook_subscriptions');
    }

    public function getDescription(): string
    {
        return 'Materialize webhook_subscriptions and webhook_deliveries (framework auto-migrated tables).';
    }
}

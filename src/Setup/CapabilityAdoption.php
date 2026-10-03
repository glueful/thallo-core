<?php

declare(strict_types=1);

namespace Thallo\Core\Setup;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\EnabledProviders;
use Glueful\Extensions\PackageManifest;
use Glueful\Extensions\Schema\DescriptorInventory;
use Glueful\Extensions\Schema\ReadinessState;
use Glueful\Extensions\Schema\SchemaReadiness;
use Glueful\Installer\DatabaseConfig;
use Thallo\Contracts\Settings\SystemChannel;
use Thallo\Core\Capabilities\Activation\ActivationStatus;
use Thallo\Core\Capabilities\Activation\ActivationStep;
use Thallo\Core\Capabilities\Activation\ActivationStore;
use Thallo\Core\Capabilities\CapabilityStateStore;
use Thallo\Core\Capabilities\CapabilityStateVersion;
use Thallo\Tenancy\System\SystemFlags;

/**
 * The one-time upgrade adoption (feature activation spec §7.3a, §7.7). Activation capabilities are
 * off until their activation finalizes, so a capability that was on under the old rule (following its
 * engine) would turn off on upgrade; this adopts it instead, once, run by provision.
 *
 *  - **capture()**, before provision's migrations, on provision's own DatabaseConfig: connects to the
 *    database provision is about to install against (stopping provision when an existing install
 *    can't be inspected), creates its one-row record table under an advisory lock, and records which
 *    capabilities are eligible **as the schema is now** — durably, and with "first committed capture
 *    wins" (INSERT … ON CONFLICT DO NOTHING, then read back). A database with no system table is a
 *    fresh install: nothing is eligible.
 *  - **run()**, after the migrations, adopts exactly the recorded ids — each only when it has no
 *    stored state and no activation in progress — then marks the record done with a compare-and-set.
 *
 * Every read and write goes to that one target connection, never to the container's connection,
 * which may still point at the database the app booted against before provision changed `.env`.
 * The candidate list is historical data: the activation capabilities that existed before this
 * release, and Payments.
 */
final class CapabilityAdoption
{
    public const DDL_LOCK = 'thallo:capability-adoption';

    public const TABLE_DDL = 'CREATE TABLE IF NOT EXISTS thallo_capability_adoption ('
        . 'id smallint PRIMARY KEY CHECK (id = 1), '
        . "state text NOT NULL CHECK (state IN ('captured', 'done')), "
        . 'eligible jsonb NOT NULL, '
        . 'captured_at timestamptz NOT NULL)';

    /** capability => its engine, and whether the old follow-the-engine rule (with config) applies. */
    private const CANDIDATES = [
        'thallo.commerce' => ['package' => 'glueful/commerce', 'oldRule' => true],
        'thallo.subscriptions' => ['package' => 'glueful/subscriptions', 'oldRule' => true],
        'thallo.payments' => ['package' => 'glueful/payvia', 'oldRule' => false],
    ];

    private ?Connection $target = null;

    public function __construct(
        private readonly ApplicationContext $context,
        private readonly DescriptorInventory $inventory,
    ) {
    }

    /**
     * @return array{state: string, eligible: list<string>} the authoritative record
     * @throws CapabilityAdoptionCaptureFailed when the database can't be inspected
     */
    public function capture(DatabaseConfig $database): array
    {
        try {
            $this->target = new Connection($database->toConnectionConfig(), $this->context);
            $this->target->getPDO()->query('SELECT 1');
        } catch (\Throwable $e) {
            throw self::failed($e);
        }
        try {
            $db = $this->target;
            $db->transaction(static function () use ($db): void {
                $pdo = $db->getPDO();
                $pdo->prepare('SELECT pg_advisory_xact_lock(hashtext(?))')->execute([self::DDL_LOCK]);
                $pdo->exec(self::TABLE_DDL);
            });
            $existing = $this->record();
            if ($existing !== null) {
                return $existing;
            }
            $eligible = $this->evaluate();
            $this->pauseForTestsBeforeInsert();
            $db->getPDO()->prepare(
                'INSERT INTO thallo_capability_adoption (id, state, eligible, captured_at)'
                . " VALUES (1, 'captured', ?::jsonb, NOW()) ON CONFLICT (id) DO NOTHING"
            )->execute([(string) json_encode($eligible)]);
            return $this->record() ?? throw new \RuntimeException('The adoption record was not written.');
        } catch (\Throwable $e) {
            throw self::failed($e);
        }
    }

    /**
     * Adopts the recorded ids (after provision's migrations), then marks the record done.
     *
     * @return list<string> the ids adopted
     */
    public function run(): array
    {
        $db = $this->target ?? throw new \LogicException('capture() must run before run(), in the same provision.');
        $record = $this->record();
        if ($record === null || $record['state'] === 'done') {
            return [];
        }
        $rows = new ActivationStore($db, $this->context->getContainer()->get(CapabilityStateStore::class));
        $version = new CapabilityStateVersion($db);
        $adopted = [];
        foreach ($record['eligible'] as $id) {
            $rows->initializeRow($id);
            $done = $db->transaction(function () use ($db, $id, $version): bool {
                $pdo = $db->getPDO();
                $stmt = $pdo->prepare('SELECT status FROM capability_activations WHERE capability = ? FOR UPDATE');
                $stmt->execute([$id]);
                $status = (string) $stmt->fetchColumn();
                if (in_array($status, ActivationStatus::OPEN, true) || $this->hasStoredState($db, $id)) {
                    return false;
                }
                $pdo->prepare('INSERT INTO thallo_system_flags (key, value, updated_at) VALUES (?, ?, ?)')
                    ->execute([CapabilityStateStore::PREFIX . $id . '.enabled', 'true', gmdate('Y-m-d H:i:s')]);
                $version->advance();
                $pdo->prepare(
                    "UPDATE capability_activations SET generation = generation + 1, status = ?, steps_done = ?::jsonb,
                       failed_step = NULL, error = NULL, remedy = NULL, owner_token = NULL, lease_expires_at = NULL,
                       result = '{\"adopted\": true}'::jsonb, actor = 'upgrade', updated_at = NOW()
                     WHERE capability = ?"
                )->execute([ActivationStatus::SUCCEEDED, (string) json_encode(ActivationStep::ALL), $id]);
                $pdo->prepare(
                    "INSERT INTO capability_activation_events (capability, generation, event, detail, actor)
                     SELECT capability, generation, 'adopted', '{}'::jsonb, 'upgrade' FROM capability_activations
                     WHERE capability = ?"
                )->execute([$id]);
                return true;
            });
            if ($done) {
                $adopted[] = $id;
            }
        }
        $db->getPDO()->exec("UPDATE thallo_capability_adoption SET state = 'done' WHERE id = 1 AND state = 'captured'");
        $this->forgetCachedFlags();
        return $adopted;
    }

    /** @return list<string> the candidates eligible as the target database is now */
    private function evaluate(): array
    {
        $db = $this->target;
        if ($db === null || $db->getPDO()->query("SELECT to_regclass('thallo_system_flags')")->fetchColumn() === null) {
            return [];                                                       // a fresh install
        }
        $enabled = EnabledProviders::from($this->context);
        $providers = [];
        foreach ((new PackageManifest($this->context))->getCandidates() as $name => $candidate) {
            $providers[(string) $name] = $candidate->provider;
        }
        $configured = config($this->context, 'thallo.capabilities', []);
        $configured = is_array($configured) ? $configured : [];
        $readiness = new SchemaReadiness($db, $this->inventory);

        $eligible = [];
        foreach (self::CANDIDATES as $id => $candidate) {
            if ($this->hasStoredState($db, $id)) {
                continue;
            }
            if ($candidate['oldRule'] && ($configured[$id] ?? true) === false) {
                continue;                                                    // the configuration switched it off
            }
            $provider = $providers[$candidate['package']] ?? null;
            if ($provider === null || !in_array($provider, $enabled, true)) {
                continue;
            }
            if (!$this->isReady($readiness, $candidate['package'])) {
                continue;
            }
            $eligible[] = $id;
        }
        return $eligible;
    }

    private function isReady(SchemaReadiness $readiness, string $package): bool
    {
        try {
            foreach ($readiness->forPackage($package) as $result) {
                if ($result['state'] !== ReadinessState::Ready) {
                    return false;
                }
            }
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function hasStoredState(Connection $db, string $id): bool
    {
        $stmt = $db->getPDO()->prepare('SELECT 1 FROM thallo_system_flags WHERE key = ?');
        $stmt->execute([CapabilityStateStore::PREFIX . $id . '.enabled']);
        return $stmt->fetchColumn() !== false;
    }

    /** @return array{state: string, eligible: list<string>}|null */
    private function record(): ?array
    {
        $row = $this->target?->getPDO()->query('SELECT state, eligible FROM thallo_capability_adoption WHERE id = 1')
            ->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $eligible = json_decode((string) $row['eligible'], true);
        return [
            'state' => (string) $row['state'],
            'eligible' => is_array($eligible) ? array_values(array_filter($eligible, 'is_string')) : [],
        ];
    }

    /** The app's system-flag cache may hold values from before these writes (same database). */
    private function forgetCachedFlags(): void
    {
        $container = $this->context->getContainer();
        if ($container->has(SystemChannel::class)) {
            $channel = $container->get(SystemChannel::class);
            if ($channel instanceof SystemFlags) {
                $channel->clearCache();
            }
        }
    }

    private static function failed(\Throwable $e): CapabilityAdoptionCaptureFailed
    {
        return new CapabilityAdoptionCaptureFailed(
            "Provision can't inspect the database it is about to migrate ({$e->getMessage()}), so it stopped "
                . 'before migrating. Fix the connection, then run php glueful thallo:provision again.',
            0,
            $e,
        );
    }

    /** Test seam (APP_ENV=testing only): pause between evaluating and writing the decision. */
    private function pauseForTestsBeforeInsert(): void
    {
        $paused = getenv('THALLO_TEST_PAUSE_IN_CAPTURE') === 'before-insert';
        if ($this->context->getEnvironment() !== 'testing' || !$paused) {
            return;
        }
        fwrite(STDOUT, "evaluated\n");
        fflush(STDOUT);
        fgets(STDIN);
    }
}

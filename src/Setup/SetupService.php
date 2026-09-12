<?php

declare(strict_types=1);

namespace Thallo\Core\Setup;

use Thallo\Core\Content\Starter\Kinds\BlockTypeKind;
use Thallo\Core\Content\Starter\Kinds\ContentTypeKind;
use Thallo\Core\Content\Starter\Kinds\RegionKind;
use Thallo\Core\Content\Starter\Kinds\SettingKind;
use Thallo\Core\Content\Starter\SeedContext;
use Thallo\Core\Settings\SystemKeys;
use Thallo\Core\Support\AuthorityMutator;
use Glueful\Auth\PasswordHasher;
use Glueful\Database\Connection;
use Glueful\Extensions\Users\Repositories\UserRepository;
use Thallo\Contracts\Settings\SystemChannel;
use Thallo\Tenancy\Tenant\SingleStoreTenant;

/**
 * Single source of truth for first-run installation.
 *
 * Creates the first admin user, writes site settings, and marks the instance as
 * installed by setting the `installed` key in `settings`. Intentionally
 * HTTP-agnostic: both the web setup endpoint and the `thallo:setup` CLI command
 * call this service directly.
 */
final class SetupService
{
    public function __construct(
        private readonly Connection $db,
        private readonly UserRepository $users,
        private readonly AuthorityMutator $authorityMutator,
        private readonly SystemChannel $system,
        private readonly ContentTypeKind $contentTypes,
        private readonly SettingKind $settings,
        private readonly RegionKind $regions,
        private readonly SingleStoreTenant $singleStore,
        private readonly InstallRoleGrants $roleGrants,
        private readonly BlockTypeKind $blockTypes,
    ) {
    }

    /**
     * Returns true when the `installed` marker has been written to the system channel.
     */
    public function isInstalled(): bool
    {
        return $this->system->get('installed') === '1';
    }

    /**
     * Runs the full first-time install inside a single database transaction.
     *
     * Steps:
     *   1. Re-checks isInstalled() to guard against races.
     *   2. Creates the admin user via UserRepository.
     *   3. Assigns the canonical superuser and administrator roles to the install user and
     *      grants those roles the full permission catalog ({@see InstallRoleGrants}).
     *   4. Writes site_name and default_locale to settings.
     *   5. Seeds "Pages" (publicly delivered, mounted at root), "Posts"
     *      (publicly delivered, prefixed) and "Categories" (the taxonomy
     *      worked-example: posts carry a filterable `categories` reference, so
     *      /post/categories/{slug} archives work) content types, and writes
     *      the `listing_types` setting (post) so listings/archives resolve —
     *      a fresh instance is immediately editable AND renderable.
     *   6. Writes the `installed` marker to settings.
     *
     * @throws \RuntimeException  When the instance is already installed.
     * @throws \InvalidArgumentException When user creation fails validation.
     */
    public function install(
        string $siteName,
        string $adminEmail,
        string $adminPassword,
        string $locale,
        ?string $adminUrl = null,
    ): void {
        $this->db->transaction(function () use (
            $siteName,
            $adminEmail,
            $adminPassword,
            $locale,
            $adminUrl,
        ): void {
            // Reduces the race window: a concurrent request that completed install between
            // the caller's isInstalled() check and this point will be caught here. This does
            // not fully close the race (no row lock), but avoids the common double-install case.
            if ($this->isInstalled()) {
                throw new \RuntimeException('Thallo is already installed.');
            }

            $hashed = (new PasswordHasher())->hash($adminPassword);

            // Use the email as the username so the first admin is unique (the users table
            // enforces both username and email uniqueness) and matches the web setup flow,
            // which collects an email but no separate username. The email is pre-verified: the
            // operator creates this account during first-run setup, so there is no one to send a
            // verification email to — mark it verified now.
            $userUuid = $this->users->create([
                'username'          => $adminEmail,
                'email'             => $adminEmail,
                'password'          => $hashed,
                'status'            => 'active',
                'email_verified_at' => date('Y-m-d H:i:s'),
            ]);

            foreach (['superuser', 'administrator'] as $roleSlug) {
                if (!$this->authorityMutator->assignRole($userUuid, $roleSlug)) {
                    throw new \RuntimeException("Failed to assign install authority role '{$roleSlug}'.");
                }
            }
            // The roles exist but Aegis seeds only its own permissions: persist the declared
            // catalog and grant the install roles everything, so the first admin can actually
            // manage content models, read the audit log and see analytics.
            $this->roleGrants->apply();

            $tenantUuid = $this->singleStore->ensure('default', $siteName, $userUuid);
            $seed = new SeedContext($tenantUuid, $siteName, $locale, $userUuid);
            // Block types included: without them a fresh instance had only the 16 slugs
            // migration 021 (re)seeded, and the rest of the starter library (rich_text, hero,
            // image …) waited for a `thallo:blocks:seed` nobody was told to run.
            foreach ([$this->contentTypes, $this->settings, $this->regions, $this->blockTypes] as $kind) {
                foreach ($kind->definitions() as $definition) {
                    $kind->apply($definition, $seed);
                }
            }
            // The web setup form sends the admin SPA's own origin, so the
            // preview bar's Edit/Design links work with zero configuration.
            if (is_string($adminUrl) && preg_match('#\Ahttps?://#i', $adminUrl) === 1) {
                $this->put('admin_url', rtrim($adminUrl, '/'));
            }

            $this->put('installed', '1');
        });
    }

    /**
     * Inserts or updates a single settings key.
     *
     * System keys (see {@see SystemKeys}) are routed to the unscoped {@see SystemChannel} so they stay
     * out of the (soon tenant-scoped) `settings` table; everything else is a manual check-then-write on
     * `settings` (the PostgreSQL upsert helper targets `ON CONFLICT (id)`, but our PK is the varchar
     * `key`). All branches run inside the caller's transaction when invoked from install() — the channel
     * (SystemFlags) shares the same Connection singleton, so its writes join the transaction too.
     */
    private function put(string $key, string $value): void
    {
        if (SystemKeys::isSystem($key)) {
            $this->system->put($key, $value);
            return;
        }

        $now = date('Y-m-d H:i:s');

        $existing = $this->db->table('settings')
            ->where(['key' => $key])
            ->first();

        if ($existing === null) {
            $this->db->table('settings')->insert([
                'key'        => $key,
                'value'      => $value,
                'updated_at' => $now,
            ]);
        } else {
            $this->db->table('settings')
                ->where(['key' => $key])
                ->update([
                    'value'      => $value,
                    'updated_at' => $now,
                ]);
        }
    }
}

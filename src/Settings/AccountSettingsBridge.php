<?php

declare(strict_types=1);

namespace Thallo\Core\Settings;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Account\Settings\AccountSettingsStore;

use function db;

/**
 * The app's implementation of thallo-account's redirect-settings contract (public-account-surface
 * plan Task 3): the two redirect overrides are ordinary {@see SettingsStore} rows — tenant-owned
 * under tenancy enforcement, runtime-mutable, clear-DELETES-the-row — so the pack reads and writes
 * them without ever naming an app class (its boundary check forbids that direction; this is the
 * same pack-defines/app-provides shape as {@see CommerceSettingsBridge}).
 *
 * `saveRedirects` wraps both writes in ONE transaction so the pair can never land half-applied.
 */
final class AccountSettingsBridge implements AccountSettingsStore
{
    private const KEY_AFTER_LOGIN = 'account.redirect.after_login';
    private const KEY_AFTER_LOGOUT = 'account.redirect.after_logout';

    public function __construct(
        private readonly SettingsStore $settings,
        private readonly ApplicationContext $context,
    ) {
    }

    public function afterLogin(): ?string
    {
        return $this->settings->get(self::KEY_AFTER_LOGIN);
    }

    public function afterLogout(): ?string
    {
        return $this->settings->get(self::KEY_AFTER_LOGOUT);
    }

    public function saveRedirects(?string $afterLogin, ?string $afterLogout): void
    {
        db($this->context)->transaction(function () use ($afterLogin, $afterLogout): void {
            $this->apply(self::KEY_AFTER_LOGIN, $afterLogin);
            $this->apply(self::KEY_AFTER_LOGOUT, $afterLogout);
        });
    }

    private function apply(string $key, ?string $value): void
    {
        if ($value === null) {
            $this->settings->forget($key);
            return;
        }
        $this->settings->putMany([$key => $value]);
    }
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Settings;

use Thallo\Commerce\Settings\CommerceSettingsStore;

/**
 * The app's implementation of thallo-commerce's storage contract (store-settings spec §3.3):
 * commerce store settings are ordinary {@see SettingsStore} rows — tenant-owned under tenancy
 * enforcement, runtime-mutable, clear-DELETES-the-row — so the pack edits/reads them without
 * ever naming an app class (its InertnessTest forbids that direction; this is the same
 * pack-defines/app-provides shape as EngineMediaUrlResolver).
 */
final class CommerceSettingsBridge implements CommerceSettingsStore
{
    public function __construct(private readonly SettingsStore $settings)
    {
    }

    public function get(string $key): ?string
    {
        return $this->settings->get($key);
    }

    /** @param array<string,string> $pairs */
    public function putMany(array $pairs): void
    {
        $this->settings->putMany($pairs);
    }

    public function forget(string $key): void
    {
        $this->settings->forget($key);
    }
}

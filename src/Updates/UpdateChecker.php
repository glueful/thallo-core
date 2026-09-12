<?php

declare(strict_types=1);

namespace Thallo\Core\Updates;

use Composer\Semver\Comparator;
use Glueful\Bootstrap\ApplicationContext;
use Thallo\Contracts\Settings\SystemChannel;

/**
 * The update notice's engine (DISTRIBUTION.md decision 11). `check()` asks the release feed for
 * the package's published versions at most once per interval, keeps the newest version the
 * install may move to in the system flags, and stays silent on failure: the previous result
 * stands and only `update.failed_at` records that a check did not complete. `status()` reads
 * the installed version at request time, so the notice clears the moment `composer update` has
 * run, before the next check. Never an updater: Composer runs as the deploy user, not here.
 *
 * Flags: `update.latest` (absent when nothing newer is published), `update.checked_at`,
 * `update.failed_at`.
 */
final class UpdateChecker
{
    public const FLAG_LATEST = 'update.latest';
    public const FLAG_CHECKED_AT = 'update.checked_at';
    public const FLAG_FAILED_AT = 'update.failed_at';

    /** A check inside this window is a no-op unless forced: one request per install per day. */
    private const MIN_INTERVAL_SECONDS = 20 * 3600;

    private readonly bool $enabled;
    private readonly string $package;
    private readonly string $notesUrl;

    /**
     * @param array{enabled?: bool, package?: string, notes_url?: string} $settings
     *        the `thallo.update_check` config block
     * @param string|null $currentVersion what Composer installed (null: unknown)
     * @param bool $development a development checkout: never checked, never notified
     */
    public function __construct(
        private readonly ReleaseFeed $feed,
        private readonly SystemChannel $flags,
        array $settings,
        private readonly ?string $currentVersion,
        private readonly bool $development,
    ) {
        $this->enabled = (bool) ($settings['enabled'] ?? true);
        $this->package = (string) ($settings['package'] ?? 'glueful/thallo-core');
        $this->notesUrl = (string) ($settings['notes_url']
            ?? 'https://github.com/glueful/thallo/blob/main/CHANGELOG.md');
    }

    /** The production wiring: settings from config, the installed version from Composer. */
    public static function fromContext(ApplicationContext $context, ReleaseFeed $feed, SystemChannel $flags): self
    {
        $settings = (array) config($context, 'thallo.update_check', []);
        $installed = InstalledVersion::of((string) ($settings['package'] ?? 'glueful/thallo-core'));

        return new self($feed, $flags, $settings, $installed['version'], $installed['development']);
    }

    public function check(bool $force = false): UpdateStatus
    {
        if (!$this->enabled || $this->development || $this->currentVersion === null) {
            return $this->status();
        }
        if (!$force && $this->checkedWithinInterval()) {
            return $this->status();
        }

        try {
            $latest = LatestVersion::pick($this->currentVersion, $this->feed->versions($this->package));
        } catch (\Throwable $e) {
            $this->flags->put(self::FLAG_FAILED_AT, date(DATE_ATOM));

            return $this->status();
        }

        if ($latest === null) {
            $this->flags->forget(self::FLAG_LATEST);
        } else {
            $this->flags->put(self::FLAG_LATEST, $latest);
        }
        $this->flags->put(self::FLAG_CHECKED_AT, date(DATE_ATOM));
        $this->flags->forget(self::FLAG_FAILED_AT);

        return $this->status();
    }

    public function status(): UpdateStatus
    {
        $current = $this->development ? null : $this->currentVersion;
        $latest = $this->enabled ? $this->flags->get(self::FLAG_LATEST) : null;
        $available = $current !== null && $latest !== null && Comparator::greaterThan($latest, $current);

        return new UpdateStatus(
            current: $current,
            latest: $latest,
            available: $available,
            development: $this->development,
            enabled: $this->enabled,
            checkedAt: $this->flags->get(self::FLAG_CHECKED_AT),
            notesUrl: $this->notesUrl,
        );
    }

    private function checkedWithinInterval(): bool
    {
        $checkedAt = $this->flags->get(self::FLAG_CHECKED_AT);
        if ($checkedAt === null) {
            return false;
        }
        $timestamp = strtotime($checkedAt);

        return $timestamp !== false && (time() - $timestamp) < self::MIN_INTERVAL_SECONDS;
    }
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Scheduling;

use Thallo\Contracts\Settings\SystemChannel;

/**
 * Proof that the scheduler is being ticked. Every job in config/schedule.php runs only when a
 * cron entry ticks `php glueful queue:scheduler run`, and a missing entry is otherwise invisible
 * until someone notices publishing never happened. The scheduled-publishing runner, driven every
 * minute by the scheduler, records the time it ran; the Health page reports it.
 */
final class SchedulerHeartbeat
{
    public const KEY = 'scheduler.last_tick';

    /** Two missed minutes are noise; five is a missing cron entry. */
    public const STALE_AFTER_SECONDS = 5 * 60;

    public const CRON_LINE = '* * * * * php /path/to/site/glueful queue:scheduler run';

    public function __construct(private readonly SystemChannel $flags)
    {
    }

    public function beat(): void
    {
        $this->flags->put(self::KEY, date(DATE_ATOM));
    }

    public function lastTick(): ?string
    {
        return $this->flags->get(self::KEY);
    }

    /**
     * A Health check in the framework's shape: ok while the last tick is recent, otherwise a
     * warning that says how long ago (or never) and names the cron line.
     *
     * @return array{status: string, message: string, recommendations?: list<string>}
     */
    public function check(): array
    {
        $last = $this->lastTick();
        $timestamp = $last === null ? false : strtotime($last);
        $recommendation = 'Add the cron entry that ticks every scheduled job: ' . self::CRON_LINE;

        if ($timestamp === false) {
            return [
                'status' => 'warning',
                'message' => 'The scheduler has never ticked: scheduled publishing, the update check and the '
                    . 'maintenance sweeps are not running.',
                'recommendations' => [$recommendation],
            ];
        }

        $age = time() - $timestamp;
        if ($age > self::STALE_AFTER_SECONDS) {
            return [
                'status' => 'warning',
                'message' => sprintf(
                    'The scheduler last ticked %s (%s ago); scheduled jobs are not running.',
                    $last,
                    self::age($age),
                ),
                'recommendations' => [$recommendation],
            ];
        }

        return ['status' => 'ok', 'message' => sprintf('Scheduler ticking; last tick %s.', $last)];
    }

    private static function age(int $seconds): string
    {
        if ($seconds < 3600) {
            return sprintf('%d min', intdiv($seconds, 60));
        }
        if ($seconds < 86400) {
            return sprintf('%d h', intdiv($seconds, 3600));
        }

        return sprintf('%d d', intdiv($seconds, 86400));
    }
}

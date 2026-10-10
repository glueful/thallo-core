<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Palette;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Contracts\Tenancy\CurrentTenantResolver;
use Glueful\Extensions\Contracts\Tenancy\TenantScope;
use Glueful\Queue\QueueManager;
use Thallo\Contracts\Style\Palette;
use Thallo\Contracts\Style\Vocabulary;
use Thallo\Core\Content\Jobs\RunPaletteReplaceJob;

/**
 * Replace with… (custom palette spec §4.2, §4.4): starting a replacement — its destinations checked
 * against an unlocked read for the 422s, then everything decided again under the palette row —
 * cancelling it and resuming it. Every status change is a guarded transition under the row; the
 * queued run carries the workspace it was started in.
 */
final class PaletteReplaceService
{
    /** A running job whose heartbeat is older than this is interrupted, and may be resumed. */
    public const STALE_SECONDS = 120;

    public function __construct(
        private readonly Connection $db,
        private readonly PaletteFence $fence,
        private readonly PaletteState $state,
        private readonly PaletteJobRepository $jobs,
        private readonly BrandColorUsage $usage,
        private readonly ?QueueManager $queue = null,
        private readonly ?ApplicationContext $context = null,
        private readonly ?CurrentTenantResolver $tenants = null,
    ) {
    }

    /**
     * @return string the job id
     * @throws PaletteConflict the slot, or a destination, is part of a replacement or not configured
     * @throws \InvalidArgumentException a destination the rules refuse, or contrast references with no mapping
     */
    public function start(int $slot, string $to, ?string $contrastTo, ?string $actor): string
    {
        $read = $this->state->snapshot();
        $this->assertDestination($read, $slot, $to, 'to');
        if ($contrastTo !== null) {
            $this->assertDestination($read, $slot, $contrastTo, 'contrast_to');
        }
        $workspace = TenantScope::current($this->tenants, $this->context);
        $id = $this->fence->within(function () use ($slot, $to, $contrastTo, $actor, $workspace): string {
            $held = $this->state->lock();
            $brand = $held->palette->brand($slot);
            if ($brand === null) {
                throw new PaletteConflict("Brand {$slot} is not configured");
            }
            if ($held->jobReplacing($slot) !== null || in_array($slot, $held->reservedSlots(), true)) {
                throw new PaletteConflict("{$brand->name} is already part of a replacement");
            }
            // The contrast decision is made here, under the row: a contrast reference saved before
            // this transaction is counted; one after is refused by the fence (no mapping, §4.2).
            $mapping = $contrastTo ?? self::pairOf($to);
            if ($mapping === null && ($this->usage->of($slot)['blocking']['contrast_references'] ?? false)) {
                throw new \InvalidArgumentException(
                    "contrast_to is required: {$to} has no text colour of its own, and text on {$brand->name} exists",
                );
            }
            foreach (array_filter([$to, $mapping]) as $destination) {
                $d = Palette::slotOf($destination);
                if ($d !== null && (!$held->palette->isConfigured($d) || $held->jobReplacing($d) !== null)) {
                    throw new PaletteConflict("Brand {$d} cannot be a destination right now");
                }
            }
            $this->state->bump();
            return $this->jobs->start($slot, $to, $mapping, $actor, $workspace);
        });
        $this->db->afterCommit(fn () => $this->queue?->push(
            RunPaletteReplaceJob::class,
            ['job_id' => $id, 'workspace' => $workspace],
        ));
        return $id;
    }

    /** @throws PaletteConflict the job is already finished or cancelled */
    public function cancel(string $jobId): void
    {
        $this->fence->within(function () use ($jobId): void {
            $this->state->lock();
            if (!$this->jobs->transition($jobId, 'cancelled')) {
                throw new PaletteConflict('That replacement has already finished');
            }
            $this->state->bump();
        });
    }

    /**
     * Resume a failed job, or an interrupted one (running, heartbeat stale). Eligibility is decided
     * under the row, so a job another worker completed or someone cancelled is never revived.
     *
     * @throws PaletteConflict
     */
    public function resume(string $jobId): void
    {
        $workspace = null;
        $this->fence->within(function () use ($jobId, &$workspace): void {
            $this->state->lock();
            $job = $this->jobs->find($jobId);
            if ($job === null || !self::resumable($job)) {
                throw new PaletteConflict('That replacement cannot be resumed');
            }
            if (!$this->jobs->transition($jobId, 'running')) {
                throw new PaletteConflict('That replacement cannot be resumed');
            }
            $this->state->bump();
            $workspace = $job->workspace;
        });
        $this->db->afterCommit(fn () => $this->queue?->push(
            RunPaletteReplaceJob::class,
            ['job_id' => $jobId, 'workspace' => $workspace],
        ));
    }

    /** A job's status as people see it: a running job with a stale heartbeat is interrupted. */
    public static function displayStatus(PaletteJob $job): string
    {
        return $job->status === 'running' && self::stale($job) ? 'interrupted' : $job->status;
    }

    /** The pair a destination carries its text colour with: Accent's and a brand slot's own. */
    public static function pairOf(string $token): ?string
    {
        if ($token === 'color.accent') {
            return 'color.accent-contrast';
        }
        $slot = Palette::slotOf($token);
        return $slot !== null && !Palette::isContrastToken($token) ? "color.brand-{$slot}-contrast" : null;
    }

    private static function resumable(PaletteJob $job): bool
    {
        return $job->status === 'failed' || ($job->status === 'running' && self::stale($job));
    }

    private static function stale(PaletteJob $job): bool
    {
        $beat = $job->heartbeatAt === null ? false : strtotime($job->heartbeatAt . ' UTC');
        return $beat === false || $beat < time() - self::STALE_SECONDS;
    }

    /** The destination rules (custom palette spec §4.2) against an unlocked read. */
    private function assertDestination(PaletteSnapshot $read, int $slot, string $token, string $field): void
    {
        $names = array_map(static fn (string $n): string => 'color.' . $n, Vocabulary::names('color'));
        if (!in_array($token, $names, true)) {
            throw new \InvalidArgumentException("{$field}: {$token} is not a colour");
        }
        $destination = Palette::slotOf($token);
        if ($destination === $slot) {
            throw new \InvalidArgumentException("{$field}: a colour cannot be replaced by itself");
        }
        if (Palette::isContrastToken($token) || $token === 'color.accent-contrast') {
            throw new \InvalidArgumentException("{$field}: choose a colour, not a text colour");
        }
        if ($destination !== null && !$read->palette->isConfigured($destination)) {
            throw new \InvalidArgumentException("{$field}: Brand {$destination} is not configured");
        }
        if ($destination !== null && $read->jobReplacing($destination) !== null) {
            throw new \InvalidArgumentException("{$field}: Brand {$destination} is being replaced");
        }
    }
}

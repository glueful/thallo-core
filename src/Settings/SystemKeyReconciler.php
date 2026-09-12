<?php

declare(strict_types=1);

namespace Thallo\Core\Settings;

use Glueful\Bootstrap\ApplicationContext;
use Thallo\Contracts\Settings\SystemChannel;
use Thallo\Contracts\Settings\SystemKeyReconciler as SystemKeyReconcilerContract;

/**
 * One-way data-move: relocate any legacy system-key rows (see {@see SystemKeys}) out of the
 * per-site `settings` table into the unscoped {@see SystemChannel}, so enabling multi-tenancy
 * cannot fragment or lose them.
 *
 * Two invariants make this safe to run repeatedly and mid-retrofit:
 *   - **channel-wins** — an existing channel value is never overwritten by a legacy `settings` row.
 *   - **verify-before-delete** — a legacy row is deleted only after confirming the channel actually
 *     holds a value for that key, so a failed write can never lose data.
 *
 * Idempotent: once the rows are moved there is nothing left in `settings` to move or delete.
 */
final class SystemKeyReconciler implements SystemKeyReconcilerContract
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly SystemChannel $channel,
    ) {
    }

    /**
     * @return list<string> the system keys whose legacy `settings` row was reconciled and removed
     */
    public function reconcile(): array
    {
        $moved = [];

        foreach (SystemKeys::KEYS as $key) {
            $row = db($this->context)->table('settings')->where(['key' => $key])->first();
            if ($row === null) {
                continue; // no legacy row for this key — nothing to move
            }

            // channel-wins: only adopt the legacy value when the channel has none yet.
            if ($this->channel->get($key) === null) {
                $this->channel->put($key, (string) ($row['value'] ?? ''));
            }

            // verify-before-delete: never drop the legacy row until the channel provably holds a value.
            if ($this->channel->get($key) === null) {
                continue;
            }

            db($this->context)->table('settings')->where(['key' => $key])->delete();
            $moved[] = $key;
        }

        return $moved;
    }
}

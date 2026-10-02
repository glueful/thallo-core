<?php

declare(strict_types=1);

namespace Thallo\Core\Capabilities\Activation;

/** Where a capability activation stands. */
final class ActivationStatus
{
    /** Pre-created, never started. */
    public const IDLE = 'idle';
    /** Open: steps are running or waiting for a fresh boot. */
    public const PREPARING = 'preparing';
    /** Open: a step failed; Retry resumes from it. */
    public const FAILED = 'failed';
    /** Closed: the capability is on. */
    public const SUCCEEDED = 'succeeded';
    /** Closed: turned off or cancelled. */
    public const SUPERSEDED = 'superseded';

    public const OPEN = [self::PREPARING, self::FAILED];
}

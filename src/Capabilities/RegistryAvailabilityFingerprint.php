<?php

declare(strict_types=1);

namespace Thallo\Core\Capabilities;

use Thallo\Contracts\Capability\AvailabilityFingerprint;
use Thallo\Contracts\Capability\CapabilityRegistry;

/**
 * The evaluated enabled state of every registered capability, hashed (search block spec §3.6). The
 * registry is the context's own, built from the snapshot the request renders with, so the
 * fingerprint names exactly the state the page was rendered under — configuration and provider
 * availability included, not only stored switches.
 */
final class RegistryAvailabilityFingerprint implements AvailabilityFingerprint
{
    private ?string $memo = null;

    public function __construct(private readonly CapabilityRegistry $registry)
    {
    }

    public function current(): string
    {
        if ($this->memo !== null) {
            return $this->memo;
        }
        $state = [];
        foreach ($this->registry->all() as $capability) {
            $state[$capability->id] = $this->registry->isEnabled($capability->id);
        }
        ksort($state);

        return $this->memo = substr(hash('sha256', json_encode($state, JSON_THROW_ON_ERROR)), 0, 12);
    }
}

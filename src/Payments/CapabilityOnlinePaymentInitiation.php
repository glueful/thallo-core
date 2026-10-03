<?php

declare(strict_types=1);

namespace Thallo\Core\Payments;

use Thallo\Contracts\Capability\CapabilityRegistry;
use Thallo\Contracts\Payments\OnlinePaymentInitiation;

/** A new online payment may start while the Payments capability (`thallo.payments`) is effective. */
final class CapabilityOnlinePaymentInitiation implements OnlinePaymentInitiation
{
    public const CAPABILITY = 'thallo.payments';

    public function __construct(private readonly CapabilityRegistry $capabilities)
    {
    }

    public function allowed(): bool
    {
        return $this->capabilities->isEnabled(self::CAPABILITY);
    }

    public function refusal(): ?string
    {
        return $this->allowed()
            ? null
            : 'Payments is off: customers pay by manual collection. '
                . 'Turn Payments on in Extensions to take online payments.';
    }
}

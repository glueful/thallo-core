<?php

declare(strict_types=1);

namespace Thallo\Core\Payments;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Contracts\Payments\PayableReference;
use Glueful\Extensions\Contracts\Payments\PaymentCollector;
use Glueful\Extensions\Contracts\Payments\PaymentInitiation;
use Thallo\Contracts\Payments\OnlinePaymentInitiation;

/**
 * The payment collector Commerce's checkout resolves (the PaymentCollector contract). While Payments
 * is off, or no gateway engine is loaded, it answers as manual collection without asking the
 * gateway; otherwise it hands the payment to Payvia's collector. Only initiation passes through
 * here, so settlement, webhooks and refunds are untouched.
 */
final class PaymentsGatedCollector implements PaymentCollector
{
    public function __construct(
        private readonly ?PaymentCollector $gateway,
        private readonly OnlinePaymentInitiation $initiation,
    ) {
    }

    public function initiate(ApplicationContext $context, PayableReference $payable): PaymentInitiation
    {
        if ($this->gateway === null || !$this->initiation->allowed()) {
            return new PaymentInitiation('manual', 'manual', [
                'instructions' => 'Payment is collected manually; an operator will mark this order paid.',
            ]);
        }
        return $this->gateway->initiate($context, $payable);
    }
}

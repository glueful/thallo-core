<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Forms;

/**
 * The narrow email seam for form notifications (form-block spec §10). Kept deliberately
 * tiny and app-owned so core has NO hard dependency on any mail/notification channel:
 * when nothing binds it, FormNotifier no-ops (submissions are still stored). An adapter
 * over the framework's email capability can be bound when that capability is installed.
 */
interface FormMailSender
{
    public function send(string $to, string $subject, string $body): void;
}

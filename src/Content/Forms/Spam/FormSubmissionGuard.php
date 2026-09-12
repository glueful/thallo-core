<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Forms\Spam;

use Thallo\Core\Content\Forms\FormDescriptor;
use Symfony\Component\HttpFoundation\Request;

/**
 * The spam-guard seam (form-block spec §8): given the request and the SEALED descriptor
 * (the authoritative honeypot field name + time-trap floor), decide whether a submission
 * proceeds. Designed so a CAPTCHA/verification guard can be composed or swapped in later
 * without touching the submit endpoint.
 */
interface FormSubmissionGuard
{
    public function check(Request $request, FormDescriptor $descriptor): GuardResult;
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Signup;

use Symfony\Component\HttpFoundation\Request;

interface SignupChallenge
{
    public function validate(Request $request): bool;
}

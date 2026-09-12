<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Starter;

enum StarterApplyResult
{
    case Applied;
    case SkippedCollision;
}

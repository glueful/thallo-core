<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Enums;

enum ScheduleAction: string
{
    case Publish = 'publish';
    case Unpublish = 'unpublish';
}

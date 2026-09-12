<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Enums;

enum EntryStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
    case Deleted = 'deleted';
}

<?php

declare(strict_types=1);

namespace Thallo\Core\Content\Enums;

enum FieldType: string
{
    case StringType = 'string';
    case Text = 'text';
    case Number = 'number';
    case Boolean = 'boolean';
    case Datetime = 'datetime';
    case EnumType = 'enum';
    case Reference = 'reference';
    case Asset = 'asset';
    case Json = 'json';
    case Blocks = 'blocks';
}

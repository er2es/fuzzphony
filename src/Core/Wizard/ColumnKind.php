<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Wizard;

/** Engine-neutral classification of a column type. */
enum ColumnKind: string
{
    case Text = 'text';
    case Int = 'int';
    case Float = 'float';
    case Bool = 'bool';
    case Date = 'date';
    case DateTime = 'datetime';
    case Uuid = 'uuid';
    case Other = 'other';
}

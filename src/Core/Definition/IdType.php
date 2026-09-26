<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Definition;

enum IdType: string
{
    case Int = 'int';
    case Uuid = 'uuid';
    case String = 'string';

    public function cast(string $value): int|string
    {
        return $this === self::Int ? (int) $value : $value;
    }
}

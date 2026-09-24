<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Definition;

enum IdType: string
{
    case Int = 'int';
    case Uuid = 'uuid';
    case String = 'string';

    public function sqlType(): string
    {
        return match ($this) {
            self::Int => 'bigint',
            self::Uuid => 'uuid',
            self::String => 'text',
        };
    }

    public function cast(string $value): int|string
    {
        return $this === self::Int ? (int) $value : $value;
    }
}

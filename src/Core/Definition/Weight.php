<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Definition;

/** Field importance. Maps to PostgreSQL tsvector weight labels (A = most important). */
enum Weight: string
{
    case A = 'A';
    case B = 'B';
    case C = 'C';
    case D = 'D';

    public static function parse(self|string $value): self
    {
        return $value instanceof self ? $value : self::from(strtoupper($value));
    }
}

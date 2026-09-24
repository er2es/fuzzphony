<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Query\Filter;

use Fuzzphony\Core\Exception\InvalidQuery;

enum Operator: string
{
    case Eq = '=';
    case Neq = '!=';
    case Lt = '<';
    case Lte = '<=';
    case Gt = '>';
    case Gte = '>=';
    case In = 'in';
    case NotIn = 'not in';
    case Between = 'between';
    case IsNull = 'is null';
    case IsNotNull = 'is not null';

    public static function parse(self|string $operator): self
    {
        if ($operator instanceof self) {
            return $operator;
        }
        $normalized = strtolower(trim((string) preg_replace('/\s+/', ' ', $operator)));

        return self::tryFrom(match ($normalized) {
            '==' => '=',
            '<>' => '!=',
            default => $normalized,
        }) ?? throw new InvalidQuery(sprintf(
            'Unknown operator "%s". Use one of: %s.',
            $operator,
            implode(', ', array_map(static fn(self $o): string => $o->value, self::cases())),
        ));
    }
}

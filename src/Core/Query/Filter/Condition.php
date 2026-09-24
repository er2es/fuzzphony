<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Query\Filter;

final readonly class Condition
{
    public function __construct(
        public string $filter,
        public Operator $operator,
        public mixed $value = null,
    ) {}
}

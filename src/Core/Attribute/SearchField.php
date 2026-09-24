<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Attribute;

use Fuzzphony\Core\Definition\Weight;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class SearchField
{
    public function __construct(
        public Weight|string $weight = Weight::B,
        public bool $fuzzy = false,
        public bool $highlight = true,
        public ?string $column = null,
    ) {}
}

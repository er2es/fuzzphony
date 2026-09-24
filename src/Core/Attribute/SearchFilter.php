<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Attribute;

use Fuzzphony\Core\Definition\FilterType;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
final readonly class SearchFilter
{
    public function __construct(
        /** Inferred from the property type when omitted (bool, int, float, string, DateTimeInterface). */
        public FilterType|string|null $type = null,
        public ?string $column = null,
    ) {}
}

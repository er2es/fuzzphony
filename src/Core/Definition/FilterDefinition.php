<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Definition;

/** A typed, indexed column that can be used in where() conditions. */
final readonly class FilterDefinition
{
    public function __construct(
        public string $name,
        public FilterType $type,
        public ?string $column = null,
        /** The values come from a child table (several per document, any of them matches); null = one value from the source row. */
        public ?ManyValues $many = null,
    ) {}

    public function column(): string
    {
        return $this->column ?? $this->name;
    }
}

<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Wizard;

final readonly class ColumnProfile
{
    public function __construct(
        public string $name,
        public ColumnKind $kind,
        public string $sqlType,
        public bool $nullable = true,
        /** Average stored length in characters/bytes, when known. */
        public ?float $averageLength = null,
        /** Estimated number of distinct values (absolute), when known. */
        public ?float $distinct = null,
        /** Approximate maximum (numeric columns), when known. */
        public ?float $approximateMax = null,
    ) {}
}

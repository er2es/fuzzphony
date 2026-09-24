<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Wizard;

final readonly class ForeignKey
{
    public function __construct(
        public string $column,
        public string $referencedTable,
        public string $referencedColumn,
        /** Columns of the referenced table, so the suggester can pick a label column (brand.name). */
        public ?TableProfile $referenced = null,
    ) {}
}

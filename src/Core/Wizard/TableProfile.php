<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Wizard;

/** What an engine knows about a table: columns, key, relations and rough statistics. */
final readonly class TableProfile
{
    /**
     * @param list<ColumnProfile> $columns
     * @param list<ForeignKey>    $foreignKeys
     * @param list<string>        $notes       e.g. "no statistics yet; sampled 1000 rows"
     */
    public function __construct(
        public string $table,
        public array $columns,
        public ?string $primaryKey,
        public int $estimatedRows = 0,
        public array $foreignKeys = [],
        public array $notes = [],
    ) {}

    public function column(string $name): ?ColumnProfile
    {
        foreach ($this->columns as $column) {
            if ($column->name === $name) {
                return $column;
            }
        }

        return null;
    }
}

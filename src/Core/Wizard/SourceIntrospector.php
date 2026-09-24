<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Wizard;

/** Engine-specific: reads table structure and statistics for the configuration wizard. */
interface SourceIntrospector
{
    /** @return list<array{table: string, rows: int}> user tables, largest first */
    public function tables(): array;

    /** @throws \InvalidArgumentException when the table does not exist */
    public function describe(string $table): TableProfile;
}

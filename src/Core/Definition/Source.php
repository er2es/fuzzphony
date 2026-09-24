<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Definition;

/**
 * Where documents come from: an existing table, or any SELECT (joins allowed) that returns
 * one row per document. Fuzzphony never alters the source; it maintains a sidecar table.
 */
final readonly class Source
{
    private function __construct(
        public ?string $table,
        public ?string $query,
        public string $idColumn,
    ) {}

    public static function table(string $table, string $idColumn = 'id'): self
    {
        return new self($table, null, $idColumn);
    }

    /** @param string $sql A SELECT returning the id column plus every field / filter column. */
    public static function query(string $sql, string $idColumn = 'id'): self
    {
        return new self(null, trim(rtrim(trim($sql), ';')), $idColumn);
    }

    public function isQuery(): bool
    {
        return $this->query !== null;
    }
}

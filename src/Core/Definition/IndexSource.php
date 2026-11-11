<?php

declare(strict_types=1);

namespace Fuzzphony\Core\Definition;

/**
 * One source of a multi-source index: a named SELECT (or table) whose rows become documents of the index, with its own watches,
 * optional entity class and id type. The name is the `kind` of its hits; it must be a lowercase identifier (so the first colon of a
 * stored key `<kind>:<id>` always ends the kind).
 */
final readonly class IndexSource
{
    /**
     * @param list<Watch>       $watches
     * @param class-string|null $entityClass
     * @param list<string>      $returnable the columns of this source a search may return with SearchBuilder::with()
     * @param array<string, string> $returnableAs the same under another name (alias => column)
     */
    public function __construct(
        public string $name,
        public Source $source,
        public array $watches = [],
        public ?string $entityClass = null,
        public IdType $idType = IdType::Int,
        public array $returnable = [],
        public array $returnableAs = [],
    ) {}

    /** The queue row (doc_id) that asks for a rebuild of one source of a multi-source index; `*` asks for the whole index. */
    public static function rebuildRequestKey(string $kind): string
    {
        return '*:' . $kind;
    }

    /** The stored key of a row of this source: `<kind>:<id>`. */
    public function key(int|string $id): string
    {
        return $this->name . ':' . $id;
    }

    /**
     * Its watches including the source table itself (for a table source), each tagged with the source.
     *
     * @return list<Watch>
     */
    public function effectiveWatches(): array
    {
        $watches = $this->watches;
        if ($this->source->table !== null) {
            $own = false;
            foreach ($watches as $watch) {
                $own = $own || $watch->table === $this->source->table;
            }
            if (!$own) {
                array_unshift($watches, new Watch($this->source->table, 'SELECT :id', $this->source->idColumn));
            }
        }

        return array_map(fn(Watch $w): Watch => new Watch($w->table, $w->affectedIds, $w->keyColumn, $w->columns, $this->name), $watches);
    }
}

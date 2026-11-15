<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Fixtures;

use Fuzzphony\Core\Definition\DefinitionProvider;
use Fuzzphony\Core\Definition\IndexDefinition;

/** The provider of docs/configuration.md "Definition providers": indexes from rows of a table (here any iterable of rows). */
// region: provider
final class RowsDefinitionProvider implements DefinitionProvider
{
    /** @param iterable<array{name: string, query: string, fields: string}> $rows e.g. SELECT name, query, fields FROM search_index_config */
    public function __construct(private readonly iterable $rows) {}

    public function definitions(): iterable
    {
        foreach ($this->rows as $row) {
            $builder = IndexDefinition::builder($row['name'])->fromQuery($row['query'])->sync('manual');
            /** @var array<string, string> $fields {"title": "A", "body": "C"} */
            $fields = json_decode($row['fields'], true, flags: \JSON_THROW_ON_ERROR);
            foreach ($fields as $field => $weight) {
                $builder->field($field, $weight);
            }
            yield $builder->build();
        }
    }
}
// endregion

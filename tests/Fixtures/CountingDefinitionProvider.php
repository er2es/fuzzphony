<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Fixtures;

use Fuzzphony\Core\Definition\DefinitionProvider;

/** A provider service without arguments (for a kernel): one index "provided_docs", counting how often it is asked. */
final class CountingDefinitionProvider implements DefinitionProvider
{
    public static int $calls = 0;

    public function definitions(): iterable
    {
        ++self::$calls;

        return (new RowsDefinitionProvider([['name' => 'provided_docs', 'query' => 'SELECT id, title FROM provided_doc', 'fields' => '{"title": "A"}']]))->definitions();
    }
}

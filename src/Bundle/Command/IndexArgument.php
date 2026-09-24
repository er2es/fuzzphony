<?php

declare(strict_types=1);

namespace Fuzzphony\Bundle\Command;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Fuzzphony;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;

/** @internal Resolves an optional "index" argument into definitions, with a helpful error. */
final class IndexArgument
{
    /** @return list<IndexDefinition> */
    public static function resolve(Fuzzphony $fuzzphony, InputInterface $input, string $name = 'index'): array
    {
        $all = $fuzzphony->registry()->all();
        if ($all === []) {
            throw new InvalidArgumentException('No search index is configured. Add #[Searchable] to an entity or define one under "fuzzphony.indexes".');
        }
        $value = $input->hasArgument($name) ? $input->getArgument($name) : $input->getOption($name);
        if (!is_string($value) || $value === '') {
            return array_values($all);
        }

        return [$fuzzphony->registry()->get($value)];
    }

    /** @return list<string> */
    public static function names(Fuzzphony $fuzzphony): array
    {
        return array_keys($fuzzphony->registry()->all());
    }
}

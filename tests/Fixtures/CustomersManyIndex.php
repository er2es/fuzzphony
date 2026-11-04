<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Fixtures;

use Fuzzphony\Core\Attribute\AsIndexDefinition;
use Fuzzphony\Core\Definition\IndexBuilder;
use Fuzzphony\Core\Definition\ManyValues;

/** A definition class with several values per document: a customer with several addresses. */
#[AsIndexDefinition('customers')]
final class CustomersManyIndex
{
    public function build(IndexBuilder $b): void
    {
        $b->fromTable('customer')
            ->field('name', 'A', fuzzy: true)
            ->field('city', 'C', many: new ManyValues('address', 'customer_id', 'city', 20))
            ->filter('city', 'string', many: ['from' => 'address', 'key' => 'customer_id', 'column' => 'city', 'limit' => 20]);
    }
}

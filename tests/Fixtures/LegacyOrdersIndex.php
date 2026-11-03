<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Fixtures;

use Fuzzphony\Core\Attribute\AsIndexDefinition;
use Fuzzphony\Core\Definition\IndexBuilder;

/** A definition class for a legacy table: no entity. */
#[AsIndexDefinition('legacy_orders')]
final class LegacyOrdersIndex
{
    public function build(IndexBuilder $b): void
    {
        $b->fromTable('legacy_order', 'order_id')->field('customer', 'A')->field('note', 'C');
    }
}

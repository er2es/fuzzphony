<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Definition;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Definition\Watch;
use PHPUnit\Framework\TestCase;

final class IndexDefinitionWatchesTest extends TestCase
{
    public function testATableSourceWatchesItsOwnTableFirst(): void
    {
        $index = IndexDefinition::builder('products')->fromTable('product', 'pid')->field('name', 'A')
            ->watch('brand', 'SELECT pid FROM product WHERE brand_id = :id')->build();

        $watches = $index->effectiveWatches();

        self::assertSame(['product', 'brand'], array_map(static fn(Watch $w): string => $w->table, $watches));
        self::assertSame('SELECT :id', $watches[0]->affectedIds);
        self::assertSame('pid', $watches[0]->keyColumn);
    }

    public function testAnExplicitWatchOfTheOwnTableReplacesTheImplicitOne(): void
    {
        $index = IndexDefinition::builder('products')->fromTable('product')->field('name', 'A')
            ->watch('brand', 'SELECT id FROM product WHERE brand_id = :id')
            ->watch('product', 'SELECT :id', columns: ['name'])
            ->watch('tag', 'SELECT id FROM product WHERE tag_id = :id')
            ->build();

        $watches = $index->effectiveWatches();

        self::assertSame(['brand', 'product', 'tag'], array_map(static fn(Watch $w): string => $w->table, $watches));
        self::assertSame(['name'], $watches[1]->columns);
    }

    public function testAQuerySourceAddsNoImplicitWatch(): void
    {
        $index = IndexDefinition::builder('products')->fromQuery('SELECT id, name FROM product')->field('name', 'A')
            ->watch('product')->build();

        self::assertSame(['product'], array_map(static fn(Watch $w): string => $w->table, $index->effectiveWatches()));
    }
}

<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Registry;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\UnknownIndex;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

final class IndexRegistryTest extends TestCase
{
    public function testUnknownIndexListsTheRegisteredOnes(): void
    {
        $registry = new IndexRegistry([Indexes::products()]);

        $this->expectException(UnknownIndex::class);
        $this->expectExceptionMessage('No search index is registered for "orders". Registered indexes: products. Add #[Searchable] to the entity or define the index under "fuzzphony.indexes".');
        $registry->get('orders');
    }

    public function testUnknownIndexOnAnEmptyRegistryReportsNone(): void
    {
        $registry = new IndexRegistry();

        $this->expectException(UnknownIndex::class);
        $this->expectExceptionMessage('No search index is registered for "orders". Registered indexes: (none). Add #[Searchable] to the entity or define the index under "fuzzphony.indexes".');
        $registry->get('orders');
    }

    public function testAllForListsEveryIndexThatReadsAClassAndForgetsAReplacedOne(): void
    {
        $single = IndexDefinition::builder('a')->fromTable('t')->field('title', 'A')->entity(\stdClass::class)->build();
        $multi = IndexDefinition::builder('b')->source('s', 'SELECT id, title FROM t', entity: \stdClass::class)->field('title', 'A')->sync('manual')->build();
        $registry = new IndexRegistry([$single, $multi]);

        self::assertSame(['a', 'b'], array_map(static fn(IndexDefinition $d): string => $d->name, $registry->allFor(\stdClass::class)));
        self::assertSame('a', $registry->get(\stdClass::class)->name);
        self::assertSame([], $registry->allFor(\ArrayObject::class));

        $registry->register(IndexDefinition::builder('a')->fromTable('t')->field('title', 'A')->build()); // replaced: no entity any more
        self::assertSame(['b'], array_map(static fn(IndexDefinition $d): string => $d->name, $registry->allFor(\stdClass::class)));
    }
}

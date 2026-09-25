<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Registry;

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
}

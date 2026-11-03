<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core;

use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Exception\UnknownIndex;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

final class FuzzphonyIndexesTest extends TestCase
{
    private function fuzzphony(): Fuzzphony
    {
        return new Fuzzphony(self::createStub(Engine::class), new IndexRegistry([Indexes::products()]));
    }

    public function testIndexesListsTheNames(): void
    {
        self::assertSame([Indexes::products()->name], $this->fuzzphony()->indexes());
    }

    public function testDefinitionReturnsTheDefinition(): void
    {
        self::assertSame(Indexes::products()->name, $this->fuzzphony()->definition(Indexes::products()->name)->name);
    }

    public function testDefinitionOfAnUnknownIndexThrows(): void
    {
        $this->expectException(UnknownIndex::class);
        $this->fuzzphony()->definition('nope');
    }
}

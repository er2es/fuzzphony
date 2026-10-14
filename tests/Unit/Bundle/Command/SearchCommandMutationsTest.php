<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Bundle\Command;

use Fuzzphony\Bundle\Command\SearchCommand;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SearchCommandMutationsTest extends TestCase
{
    /** Programmatic callers (ArrayInput) may hand non-string facet values: they become names, so the index rejects an unknown one by name instead of a TypeError. */
    public function testFacetNamesAreCoercedToStrings(): void
    {
        $tester = new CommandTester(new SearchCommand(new Fuzzphony(self::createStub(Engine::class), new IndexRegistry([Indexes::products('manual')]))));

        $this->expectException(InvalidQuery::class);
        $this->expectExceptionMessage('has no filter "5"');

        $tester->execute(['index' => 'products', 'query' => 'mouse', '--facet' => [5]], ['interactive' => false]);
    }
}

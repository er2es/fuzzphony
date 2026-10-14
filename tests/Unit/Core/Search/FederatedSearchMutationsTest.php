<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Search;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\FederatedSearch;
use Fuzzphony\Core\Search\SearchResult;
use PHPUnit\Framework\TestCase;

final class FederatedSearchMutationsTest extends TestCase
{
    public function testRepeatedWarningsAreListedOnceAndTheListStaysAList(): void
    {
        $result = $this->search(['products' => ['dup', 'dup', 'other']])->index('products')->query('mouse')->get();

        self::assertSame(['[products] dup', '[products] other'], $result->warnings);
    }

    public function testTheTimeIsRoundedToThreeDecimalsAndKeepsItsFraction(): void
    {
        $search = $this->search([])->index('products')->query('mouse');
        $fractional = false;

        // hrtime() cannot be injected: look at many readings (each one is a fresh, almost instant search)
        for ($i = 0; $i < 300; ++$i) {
            $took = $search->get()->tookMs;
            self::assertSame(round($took, 3), $took, 'at most three decimals');
            $fractional = $fractional || round($took, 2) !== $took;
        }

        self::assertTrue($fractional, 'milliseconds with a fraction of a thousandth are kept, not floored, ceiled or cut to hundredths');
    }

    /** @param array<string, list<string>> $warnings by index name */
    private function search(array $warnings): FederatedSearch
    {
        $engine = self::createStub(Engine::class);
        $engine->method('search')->willReturnCallback(static fn(IndexDefinition $index): SearchResult => SearchResult::empty(20, 0, $warnings[$index->name] ?? []));
        $registry = new IndexRegistry([IndexDefinition::builder('products')->fromTable('product')->field('name', 'A')->build()]);

        return new FederatedSearch($engine, $registry);
    }
}

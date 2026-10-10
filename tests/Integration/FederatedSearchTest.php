<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Core\Exception\UnknownIndex;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\FederatedSearch;
use Fuzzphony\Core\Search\SearchBuilder;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** One query over several indexes, merged by reciprocal rank fusion. */
final class FederatedSearchTest extends TestCase
{
    private function fuzzphony(): Fuzzphony
    {
        $connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($connection, EngineConformanceTestCase::fixtureRows());
        $brands = IndexDefinition::builder('brands')->fromTable('fz_brand')->field('name', 'A', fuzzy: true)->language('english')->sync('manual')->build();
        $fuzzphony = new Fuzzphony(new PostgresEngine($connection), new IndexRegistry([Indexes::products('manual'), $brands]));
        $fuzzphony->schema()->apply($connection);
        $fuzzphony->reindex('products');
        $fuzzphony->reindex('brands');

        return $fuzzphony;
    }

    /** @return list<string> "index:id" in the merged order */
    private static function order(\Fuzzphony\Core\Search\FederatedResult $result): array
    {
        return array_map(static fn(\Fuzzphony\Core\Search\FederatedHit $h): string => $h->index . ':' . $h->hit->id, $result->hits);
    }

    public function testTheBestHitOfEveryIndexComesFirst(): void
    {
        $result = $this->fuzzphony()->federated()->index('products')->index('brands')->query('sony')->get();

        // "sony" is brand 3 and the brand of the headphones (3) and the torch (5): rank 1 of each index first, then the rest
        self::assertSame('products', $result->hits[0]->index);
        self::assertSame('brands:3', self::order($result)[1]);
        self::assertEqualsCanonicalizing(['products:3', 'products:5', 'brands:3'], self::order($result));
        self::assertSame($result->hits[0]->score, $result->hits[1]->score, 'rank 1 of each index');
        self::assertGreaterThan($result->hits[2]->score, $result->hits[1]->score, 'rank 2 of the products');
        self::assertSame($result->results['products']->total + $result->results['brands']->total, $result->total);
        self::assertSame(['products', 'brands'], array_keys($result->results));
        self::assertSame(1 / (FederatedSearch::RANK_CONSTANT + 1), $result->hits[0]->score);
    }

    public function testAWeightMovesAnIndexUp(): void
    {
        $plain = $this->fuzzphony()->federated()->index('products')->index('brands')->query('sony')->get();
        $weighted = $this->fuzzphony()->federated()->index('products')->index('brands', weight: 2.0)->query('sony')->get();

        self::assertSame('products', $plain->hits[0]->index, 'equal scores: the index added first');
        self::assertSame('brands:3', self::order($weighted)[0], 'the brand outranks the products with weight 2');
        self::assertSame(2.0 / (FederatedSearch::RANK_CONSTANT + 1), $weighted->hits[0]->score);
    }

    public function testEachIndexIsConfiguredOnItsOwnAndPagesAreSlicesOfTheMergedList(): void
    {
        $fuzzphony = $this->fuzzphony();
        $search = $fuzzphony->federated()
            ->index('products', configure: static fn(SearchBuilder $b): SearchBuilder => $b->where('in_stock', true)->highlight('name'))
            ->index('brands')
            ->query('sony');

        $all = $search->limit(10)->get();
        self::assertNotContains('products:3', self::order($all), 'the headphones are out of stock: the products closure filtered them');
        self::assertContains('products:5', self::order($all));

        $first = $search->limit(1)->get();
        $second = $search->limit(1, 1)->get();
        self::assertSame(array_slice(self::order($all), 0, 1), self::order($first));
        self::assertSame(array_slice(self::order($all), 1, 1), self::order($second));
        self::assertSame(array_slice(self::order($all), 1, 1), self::order($search->page(2, 1)->get()));
        self::assertTrue($first->hasMore());
        self::assertCount(1, $first);
        self::assertSame(self::order($first), array_map(static fn($h): string => $h->index . ':' . $h->hit->id, iterator_to_array($first)));
    }

    public function testWarningsNameTheirIndex(): void
    {
        $result = $this->fuzzphony()->federated()->index('products')->index('brands')->query('***')->get();

        self::assertSame([], $result->hits);
        self::assertStringContainsString('[products] ', implode("\n", $result->warnings));
        self::assertStringContainsString('[brands] ', implode("\n", $result->warnings));
    }

    public function testTheConfigurationIsChecked(): void
    {
        $fuzzphony = $this->fuzzphony();
        $search = $fuzzphony->federated();

        foreach ([
            static fn() => $search->get(),
            static fn() => $search->index('products')->index('products'),
            static fn() => $search->index('products', weight: 0.0),
            static fn() => $search->limit(0),
            static fn() => $search->limit(900, 200),
        ] as $call) {
            try {
                $call();
                self::fail('Expected InvalidQuery.');
            } catch (InvalidQuery $e) {
                self::assertNotSame('', $e->getMessage());
            }
        }
        $this->expectException(UnknownIndex::class);
        $search->index('nope');
    }
}

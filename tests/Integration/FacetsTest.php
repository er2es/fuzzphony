<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Integration;

use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Core\Fuzzphony;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\FacetValue;
use Fuzzphony\Engine\Postgres\PostgresEngine;
use Fuzzphony\Tests\Conformance\EngineConformanceTestCase;
use Fuzzphony\Tests\Fixtures\Indexes;
use PHPUnit\Framework\TestCase;

/** Facets: counts per filter value among the matches, the exact counts, and the tenant boundary. */
final class FacetsTest extends TestCase
{
    /** @param array{brands: list<array{int, string}>, products: list<array{int, string, string, int, int, bool, float, string}>}|null $rows */
    private function fuzzphony(?array $rows = null, bool $tenant = false): Fuzzphony
    {
        $connection = PostgresTestCase::connect();
        PostgresTestCase::createFixtures($connection, $rows ?? EngineConformanceTestCase::fixtureRows());
        $fuzzphony = new Fuzzphony(new PostgresEngine($connection), new IndexRegistry([Indexes::products('manual', tenant: $tenant)]));
        $fuzzphony->schema()->apply($connection);
        $fuzzphony->reindex('products');

        return $fuzzphony;
    }

    /**
     * @param list<FacetValue> $values
     *
     * @return array<string, int>
     */
    private static function counts(array $values): array
    {
        $counts = [];
        foreach ($values as $facet) {
            $counts[var_export($facet->value, true)] = $facet->count;
        }

        return $counts;
    }

    public function testTheValuesOfAFilterAreCountedAmongTheMatches(): void
    {
        $result = $this->fuzzphony()->in('products')->query('mouse')->facets('brand_id', 'in_stock')->get();

        self::assertEqualsCanonicalizing([1, 2, 4], $result->ids());
        self::assertSame(['1' => 2, '2' => 1], self::counts($result->facets['brand_id']), 'the most frequent value first');
        self::assertSame(['true' => 3], self::counts($result->facets['in_stock']));
        self::assertContainsOnlyInstancesOf(FacetValue::class, $result->facets['brand_id']);
        self::assertSame(1, $result->facets['brand_id'][0]->value, 'an int filter gives ints');
        self::assertTrue($result->facets['in_stock'][0]->value, 'a bool filter gives bools');
    }

    public function testAFacetDoesNotCountItsOwnConditionButTheOthersDo(): void
    {
        $result = $this->fuzzphony()->in('products')->query('mouse')->where('brand_id', 2)->facets('brand_id', 'in_stock')->get();

        self::assertSame([2], $result->ids());
        self::assertSame(['1' => 2, '2' => 1], self::counts($result->facets['brand_id']), 'what choosing another brand would find');
        self::assertSame(['true' => 1], self::counts($result->facets['in_stock']), 'the brand condition counts for the other facets');

        $inStock = $this->fuzzphony()->in('products')->query('wireless')->where('in_stock', true)->facets('in_stock')->get();
        self::assertSame(['false' => 1, 'true' => 1], self::counts($inStock->facets['in_stock']), 'the headphones are out of stock');
    }

    public function testAFilterOnlySearchIsCountedToo(): void
    {
        $result = $this->fuzzphony()->in('products')->facets('in_stock')->get();

        self::assertSame(['true' => 4, 'false' => 1], self::counts($result->facets['in_stock']));
        self::assertSame(['false' => 1], self::counts($this->fuzzphony()->in('products')->where('price', '>', 40_000)->facets('in_stock')->get()->facets['in_stock']));
    }

    public function testFacetsFollowTheRelaxedSearch(): void
    {
        $result = $this->fuzzphony()->in('products')->query('mouse zzzzzz')->facets('brand_id')->get();

        self::assertStringContainsString('zzzzzz', implode(' ', $result->warnings));
        self::assertSame(['1' => 2, '2' => 1], self::counts($result->facets['brand_id']), 'the words that matched nothing were dropped, and so for the facet');
    }

    public function testTheMostFrequentValuesAreKeptAndTheLimitIsChecked(): void
    {
        $fuzzphony = $this->fuzzphony();

        self::assertCount(1, $fuzzphony->in('products')->query('mouse')->facets('brand_id')->facetValues(1)->get()->facets['brand_id']);
        foreach ([0, 101] as $values) {
            try {
                $fuzzphony->in('products')->facetValues($values);
                self::fail('Expected InvalidQuery.');
            } catch (InvalidQuery $e) {
                self::assertStringContainsString('between 1 and 100', $e->getMessage());
            }
        }
    }

    public function testOnlyFiltersThatCanBeCountedAreFacets(): void
    {
        $builder = $this->fuzzphony()->in('products');

        foreach (['published_at' => 'datetime', 'nope' => 'nope'] as $filter => $why) {
            try {
                $builder->facets($filter);
                self::fail('Expected InvalidQuery.');
            } catch (InvalidQuery $e) {
                self::assertStringContainsString($why, $e->getMessage());
            }
        }
    }

    public function testExactCountsCountEveryMatchNotOnlyTheCandidates(): void
    {
        $products = [];
        for ($i = 1; $i <= 40; ++$i) {
            $products[] = [$i, 'Mouse model ' . $i, 'A mouse', 1 + $i % 2, 1_000 + $i, true, 1.0, 'now'];
        }
        $fuzzphony = $this->fuzzphony(['brands' => [[1, 'Acme'], [2, 'Globex']], 'products' => $products]);
        $search = $fuzzphony->in('products')->query('mouse')->thresholds(['candidate_limit' => 10, 'fuzzy_mode' => 'never'])->facets('brand_id');

        $approximate = $search->get();
        self::assertTrue($approximate->totalIsLowerBound);
        self::assertSame(10, $approximate->total);
        self::assertSame(10, array_sum(self::counts($approximate->facets['brand_id'])), 'the candidates only');

        $exact = $search->exactCounts()->get();
        self::assertFalse($exact->totalIsLowerBound);
        self::assertSame(40, $exact->total);
        self::assertSame(['1' => 20, '2' => 20], self::counts($exact->facets['brand_id']));
        self::assertCount(10, $exact->hits, 'the page is still the ranked candidates');
        self::assertContains('exact total', array_map(static fn(array $s): string => $s['label'], $search->exactCounts()->explain()->statements));
    }

    public function testExactCountsAreCheapWhenNothingWasCut(): void
    {
        $explanation = $this->fuzzphony()->in('products')->query('mouse')->exactCounts()->facets('in_stock')->explain();

        self::assertSame(3, $explanation->result->total);
        self::assertNotContains('exact total', array_map(static fn(array $s): string => $s['label'], $explanation->statements), 'the total was exact already');
        self::assertStringContainsString('facet: in_stock', implode(' ', array_map(static fn(array $s): string => $s['label'], $explanation->statements)));
        self::assertNotSame([], $explanation->plan, 'the plan is the search statement, not a facet');
    }

    public function testAFilterOnlySearchCountsExactlyToo(): void
    {
        $products = [];
        for ($i = 1; $i <= 30; ++$i) {
            $products[] = [$i, 'Item ' . $i, 'x', 1, 100 + $i, $i % 3 !== 0, 1.0, 'now'];
        }
        $fuzzphony = $this->fuzzphony(['brands' => [[1, 'Acme']], 'products' => $products]);
        $browse = $fuzzphony->in('products')->thresholds(['candidate_limit' => 10])->facets('in_stock');

        self::assertSame(10, array_sum(self::counts($browse->get()->facets['in_stock'])));
        $exact = $browse->exactCounts()->get();
        self::assertSame(30, $exact->total);
        self::assertFalse($exact->totalIsLowerBound);
        self::assertSame(['true' => 20, 'false' => 10], self::counts($exact->facets['in_stock']));
    }

    public function testTheTenantBoundaryHoldsForFacets(): void
    {
        $fuzzphony = $this->fuzzphony(tenant: true);

        $own = $fuzzphony->in('products')->forTenant(1)->query('mouse')->facets('in_stock')->get();
        self::assertSame(['true' => 2], self::counts($own->facets['in_stock']), 'brand 1 only: the other tenant\'s mouse is not counted');

        $this->expectException(InvalidQuery::class);
        $this->expectExceptionMessage('tenant filter');
        $fuzzphony->in('products')->forTenant(1)->facets('brand_id');
    }
}

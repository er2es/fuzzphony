<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Search;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Core\Query\SearchQuery;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\FederatedResult;
use Fuzzphony\Core\Search\FederatedSearch;
use Fuzzphony\Core\Search\Hit;
use Fuzzphony\Core\Search\ScoreBreakdown;
use Fuzzphony\Core\Search\SearchResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Weights and per-index floors of the federated merge, against a fake engine. */
final class FederatedFloorTest extends TestCase
{
    /** @var array<string, SearchQuery> */
    private array $asked = [];

    /** @param array<string, list<int>> $ids hit ids by index, in rank order */
    private function search(array $ids): FederatedSearch
    {
        $engine = self::createStub(Engine::class);
        $engine->method('search')->willReturnCallback(function (IndexDefinition $index, SearchQuery $query) use ($ids): SearchResult {
            $this->asked[$index->name] = $query;
            $hits = array_slice(array_map(static fn(int $id): Hit => new Hit($index->name, $id, 1.0, new ScoreBreakdown()), $ids[$index->name] ?? []), 0, $query->offset + $query->limit);

            return new SearchResult($hits, count($hits), false, 0.1, false, [], $query->limit, $query->offset);
        });
        $registry = new IndexRegistry([
            IndexDefinition::builder('products')->fromTable('product')->field('name', 'A')->build(),
            IndexDefinition::builder('articles')->fromTable('article')->field('name', 'A')->build(),
            IndexDefinition::builder('brands')->fromTable('brand')->field('name', 'A')->build(),
        ]);

        return new FederatedSearch($engine, $registry);
    }

    /** @return list<int> */
    private static function ids(FederatedResult $r): array
    {
        return array_map(static fn(Hit $h): int => (int) $h->id, $r->hits);
    }

    public function testWeightsReorderTheMergedList(): void
    {
        $data = ['products' => [1, 2, 3], 'articles' => [11, 12, 13]];

        self::assertSame([1, 11, 2, 12, 3, 13], self::ids($this->search($data)->index('products')->index('articles')->get()));
        // 2 / 63 still beats 1 / 61: a double weight puts the whole products list first
        self::assertSame([1, 2, 3, 11, 12, 13], self::ids($this->search($data)->index('products', weight: 2.0)->index('articles')->get()));
        // a lighter first index: its best hit (0.5 / 61) falls below every hit of articles (1 / 63 at least)
        self::assertSame([11, 12, 13, 1, 2, 3], self::ids($this->search($data)->index('products', weight: 0.5)->index('articles')->get()));
    }

    #[DataProvider('badWeights')]
    public function testABadWeightIsRefused(float $weight): void
    {
        $this->expectException(InvalidQuery::class);

        $this->search([])->index('products', weight: $weight);
    }

    /** @return iterable<string, array{float}> */
    public static function badWeights(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-1.0];
        yield 'nan' => [NAN];
        yield 'infinite' => [INF];
    }

    public function testANegativeFloorIsRefused(): void
    {
        $this->expectException(InvalidQuery::class);

        $this->search([])->index('products', atLeast: -1);
    }

    public function testAFloorPromotesAWeakIndexOverTheLowestScoredHits(): void
    {
        // products ten times heavier: without a floor all five slots are theirs
        $data = ['products' => [1, 2, 3, 4, 5, 6], 'articles' => [11, 12, 13]];

        self::assertSame([1, 2, 3, 4, 5], self::ids($this->search($data)->index('products', weight: 10.0)->index('articles')->limit(5)->get()));
        self::assertSame([1, 2, 3, 11, 12], self::ids($this->search($data)->index('products', weight: 10.0)->index('articles', atLeast: 2)->limit(5)->get()));
    }

    public function testAFloorMayTakeTheOnlySlotOfAOnePage(): void
    {
        $data = ['products' => [1, 2], 'articles' => [11, 12]];

        self::assertSame([11], self::ids($this->search($data)->index('products', weight: 10.0)->index('articles', atLeast: 1)->limit(1)->get()));
    }

    public function testAFloorDoesNothingWhenTheIndexAlreadyRanksHigh(): void
    {
        $data = ['products' => [1, 2, 3], 'articles' => [11, 12, 13]];
        $plain = $this->search($data)->index('products')->index('articles')->limit(4)->get();
        $floored = $this->search($data)->index('products')->index('articles', atLeast: 2)->limit(4)->get();

        self::assertSame(self::ids($plain), self::ids($floored));
        self::assertSame([1, 11, 2, 12], self::ids($floored));
    }

    public function testAnIndexWithFewerHitsThanItsFloorGetsAllItHas(): void
    {
        $data = ['products' => [1, 2, 3, 4], 'articles' => [11]];
        $result = $this->search($data)->index('products', weight: 10.0)->index('articles', atLeast: 3)->limit(3)->get();

        self::assertSame([1, 2, 11], self::ids($result));
    }

    public function testAnEmptyIndexWithAFloorChangesNothing(): void
    {
        $data = ['products' => [1, 2, 3], 'articles' => []];

        self::assertSame([1, 2, 3], self::ids($this->search($data)->index('products')->index('articles', atLeast: 2)->limit(3)->get()));
    }

    public function testFloorsAboveTheLimitAreRefused(): void
    {
        $this->expectException(InvalidQuery::class);
        $this->expectExceptionMessage('add up to 5');

        $this->search([])->index('products', atLeast: 3)->index('articles', atLeast: 2)->limit(4)->get();
    }

    public function testSeveralFloorsFillThePageAndKeepTheMergedOrder(): void
    {
        $data = ['products' => [1, 2, 3, 4, 5, 6], 'articles' => [11, 12, 13], 'brands' => [21, 22, 23]];
        $result = $this->search($data)->index('products', weight: 10.0)->index('articles', atLeast: 2)->index('brands', atLeast: 2)->limit(5)->get();

        self::assertSame([1, 11, 21, 12, 22], self::ids($result));
        $scores = array_map(static fn(Hit $h): ?float => $h->mergedScore, $result->hits);
        $sorted = $scores;
        rsort($sorted);
        self::assertSame($sorted, $scores);
    }

    public function testPagesNeverDuplicateOrLoseAHit(): void
    {
        $data = ['products' => range(1, 12), 'articles' => range(101, 106), 'brands' => range(201, 204)];
        $seen = [];
        for ($page = 1; $page <= 4; ++$page) {
            $result = $this->search($data)->index('products', weight: 10.0)->index('articles', atLeast: 2)->index('brands', atLeast: 3)->page($page, 6)->get();
            if ($page === 1) {
                self::assertCount(3, array_filter(self::ids($result), static fn(int $id): bool => $id > 200));
                self::assertCount(2, array_filter(self::ids($result), static fn(int $id): bool => $id > 100 && $id < 200));
            }
            $seen = [...$seen, ...self::ids($result)];
        }
        sort($seen);

        self::assertSame([...range(1, 12), ...range(101, 106), ...range(201, 204)], $seen);
    }

    public function testEveryIndexIsReadForOffsetPlusLimit(): void
    {
        $this->search([])->index('products')->index('articles', atLeast: 2)->limit(5, 5)->get();

        self::assertSame(10, $this->asked['articles']->limit);
        self::assertSame(10, $this->asked['products']->limit);
    }
}

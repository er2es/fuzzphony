<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Search;

use Fuzzphony\Core\Definition\IndexDefinition;
use Fuzzphony\Core\Engine\Engine;
use Fuzzphony\Core\Exception\InvalidQuery;
use Fuzzphony\Core\Query\SearchQuery;
use Fuzzphony\Core\Registry\IndexRegistry;
use Fuzzphony\Core\Search\FederatedSearch;
use Fuzzphony\Core\Search\Hit;
use Fuzzphony\Core\Search\ScoreBreakdown;
use Fuzzphony\Core\Search\SearchResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The federated search against a fake engine: no database needed. */
final class FederatedSearchBehaviourTest extends TestCase
{
    /** @var list<SearchQuery> */
    private array $asked = [];

    /** @param array<string, SearchResult> $results by index name */
    private function search(array $results = []): FederatedSearch
    {
        $engine = self::createStub(Engine::class);
        $engine->method('search')->willReturnCallback(function (IndexDefinition $index, SearchQuery $query) use ($results): SearchResult {
            $this->asked[] = $query;

            return $results[$index->name] ?? SearchResult::empty($query->limit, $query->offset);
        });
        $registry = new IndexRegistry([
            IndexDefinition::builder('products')->fromTable('product')->field('name', 'A')->build(),
            IndexDefinition::builder('brands')->fromTable('brand')->field('name', 'A')->build(),
        ]);

        return new FederatedSearch($engine, $registry);
    }

    /**
     * @param list<int>    $ids
     * @param list<string> $warnings
     */
    private static function resultOf(array $ids, int $total = 0, bool $lowerBound = false, array $warnings = []): SearchResult
    {
        return new SearchResult(
            array_map(static fn(int $id): Hit => new Hit($id, 1.0, new ScoreBreakdown()), $ids),
            $total === 0 ? count($ids) : $total,
            $lowerBound,
            0.1,
            false,
            $warnings,
            20,
            0,
        );
    }

    public function testTwentyHitsAreReadFromEveryIndexByDefault(): void
    {
        $result = $this->search()->index('products')->query('mouse')->get();

        self::assertSame(20, $result->limit);
        self::assertSame(0, $result->offset);
        self::assertSame(20, $this->asked[0]->limit);
    }

    /** @return iterable<string, array{int, int, bool}> */
    public static function limits(): iterable
    {
        yield 'offset + limit of exactly 1000' => [1000, 0, true];
        yield 'one more than 1000' => [999, 2, false];
        yield 'the largest page' => [1, 999, true];
        yield 'no hits' => [0, 0, false];
        yield 'negative offset' => [10, -1, false];
    }

    #[DataProvider('limits')]
    public function testTheMostThatIsReadIsOneThousandPerIndex(int $limit, int $offset, bool $allowed): void
    {
        if (!$allowed) {
            $this->expectException(InvalidQuery::class);
        }

        $search = $this->search()->limit($limit, $offset);

        self::assertInstanceOf(FederatedSearch::class, $search);
    }

    public function testTheLimitMessageNamesTheNumbers(): void
    {
        $this->expectException(InvalidQuery::class);
        $this->expectExceptionMessage('offset + limit at most 1000, got 200 + 900.');
        $this->search()->limit(900, 200);
    }

    /** @return iterable<string, array{int, int|null, int, int}> */
    public static function pages(): iterable
    {
        yield 'first page, default size' => [1, null, 20, 0];
        yield 'third page, default size' => [3, null, 20, 40];
        yield 'page zero is the first page' => [0, null, 20, 0];
        yield 'custom size' => [3, 10, 10, 20];
    }

    #[DataProvider('pages')]
    public function testPageIsALimitAndAnOffset(int $page, ?int $perPage, int $limit, int $offset): void
    {
        $search = $this->search()->index('products');
        $result = ($perPage === null ? $search->page($page) : $search->page($page, $perPage))->get();

        self::assertSame($limit, $result->limit);
        self::assertSame($offset, $result->offset);
        self::assertSame($limit + $offset, $this->asked[0]->limit);
    }

    public function testTheTotalIsALowerBoundWhenAnyIndexSaysSo(): void
    {
        $exact = $this->search(['products' => self::resultOf([1]), 'brands' => self::resultOf([2])])->index('products')->index('brands')->get();
        $partial = $this->search(['products' => self::resultOf([1]), 'brands' => self::resultOf([2], 40, true)])->index('products')->index('brands')->get();

        self::assertFalse($exact->totalIsLowerBound);
        self::assertTrue($partial->totalIsLowerBound);
        self::assertSame(41, $partial->total);
    }

    public function testWarningsAreNamedAndListedOnce(): void
    {
        $result = $this->search(['products' => self::resultOf([1], 1, false, ['cut', 'cut'])])->index('products')->index('brands')->get();

        self::assertSame(['[products] cut'], $result->warnings);
    }

    public function testTheTimeIsInMilliseconds(): void
    {
        $result = $this->search()->index('products')->get();

        self::assertGreaterThanOrEqual(0.0, $result->tookMs);
        self::assertLessThan(10_000.0, $result->tookMs);
    }
}

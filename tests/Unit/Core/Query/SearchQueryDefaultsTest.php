<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Query;

use Fuzzphony\Core\Query\Filter\Condition;
use Fuzzphony\Core\Query\Filter\Operator;
use Fuzzphony\Core\Query\SearchQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SearchQueryDefaultsTest extends TestCase
{
    public function testWhereNullDefaultsToIsNull(): void
    {
        self::assertSame(Operator::IsNull, (new SearchQuery())->whereNull('deleted_at')->conditions[0]->operator);
        self::assertSame(Operator::IsNotNull, (new SearchQuery())->whereNull('deleted_at', false)->conditions[0]->operator);
    }

    /** @return iterable<string, array{int, int|null, int, int}> */
    public static function pages(): iterable
    {
        yield 'first page, default size' => [1, null, 20, 0];
        yield 'third page, default size' => [3, null, 20, 40];
        yield 'page zero is the first page' => [0, null, 20, 0];
        yield 'a negative page is the first page' => [-4, 10, 10, 0];
        yield 'custom size' => [2, 5, 5, 5];
    }

    #[DataProvider('pages')]
    public function testPageComputesLimitAndOffset(int $page, ?int $perPage, int $limit, int $offset): void
    {
        $query = $perPage === null ? (new SearchQuery())->page($page) : (new SearchQuery())->page($page, $perPage);

        self::assertSame($limit, $query->limit);
        self::assertSame($offset, $query->offset);
    }

    public function testThresholdOverridesAccumulate(): void
    {
        $query = (new SearchQuery())->thresholds(['min_score' => 0.1])->thresholds(['fuzzy_mode' => 'always'])->thresholds(['min_score' => 0.2]);

        self::assertSame(['min_score' => 0.2, 'fuzzy_mode' => 'always'], $query->thresholdOverrides);
    }

    public function testFacetsAreListedOnceAndAsAList(): void
    {
        $query = (new SearchQuery())->facets('brand', 'colour')->facets('brand', 'size');

        self::assertSame(['brand', 'colour', 'size'], $query->facets);
    }

    public function testAsYouTypeIsOnUnlessSwitchedOff(): void
    {
        self::assertTrue((new SearchQuery())->asYouType()->asYouType);
        self::assertFalse((new SearchQuery())->asYouType()->asYouType(false)->asYouType);
    }

    public function testLimitResetsTheOffsetUnlessGiven(): void
    {
        $query = (new SearchQuery())->limit(10, 30)->limit(5);

        self::assertSame(5, $query->limit);
        self::assertSame(0, $query->offset);
    }

    public function testExactCountsAreOnUnlessSwitchedOff(): void
    {
        self::assertTrue((new SearchQuery())->exactCounts()->exactCounts);
        self::assertFalse((new SearchQuery())->exactCounts()->exactCounts(false)->exactCounts);
    }

    public function testAConditionCanBeAddedDirectly(): void
    {
        $condition = new Condition('price', Operator::Gt, 5);

        self::assertSame([$condition], (new SearchQuery())->withCondition($condition)->conditions);
    }
}

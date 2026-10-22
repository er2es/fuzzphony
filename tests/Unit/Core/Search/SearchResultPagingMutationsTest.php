<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Search;

use Fuzzphony\Core\Search\FederatedHit;
use Fuzzphony\Core\Search\FederatedResult;
use Fuzzphony\Core\Search\Hit;
use Fuzzphony\Core\Search\ScoreBreakdown;
use Fuzzphony\Core\Search\SearchResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** hasMore() of both result types: the page window is offset .. offset + hits, compared to the total. */
final class SearchResultPagingMutationsTest extends TestCase
{
    private function hit(int $id): Hit
    {
        return new Hit($id, 1.0, new ScoreBreakdown());
    }

    /** @return iterable<string, array{int, int, int, bool, bool}> offset, hits on the page, total, total is a lower bound, expected */
    public static function pages(): iterable
    {
        yield 'first page, more follow' => [0, 2, 5, false, true];
        yield 'first page, exactly the total' => [0, 2, 2, false, false];
        yield 'a later page that ends exactly at the total' => [20, 1, 21, false, false];
        yield 'a later page with one more to come' => [20, 1, 22, false, true];
        yield 'a later page, total below the window end is not more' => [20, 1, 20, false, false];
        yield 'the last page of the candidates has no more, although the total is a lower bound' => [0, 1, 1, true, false];
        yield 'a lower-bound total on a later page that ends at the candidates has no more' => [20, 1, 21, true, false];
        yield 'a lower-bound total with candidates to come has more' => [0, 20, 2000, true, true];
        yield 'a lower-bound total, the page before the last candidates, has more' => [1960, 20, 2000, true, true];
        yield 'a lower-bound total, the last page of the candidates, has none' => [1980, 20, 2000, true, false];
    }

    /** @return iterable<string, array{int, int, int, bool, bool}> offset, hits on the page, total, total is a lower bound, expected */
    public static function exactPages(): iterable
    {
        yield 'exact counts: the total counts every match, hits are read up to the hard cap' => [9960, 20, 200_000, false, true];
        yield 'exact counts: the page that ends at the hard cap has no more' => [9980, 20, 200_000, false, false];
        yield 'exact counts: a page past the hard cap is empty and has no more' => [10_000, 0, 200_000, false, false];
        yield 'exact counts: far past the hard cap' => [50_000, 0, 200_000, false, false];
        yield 'an empty page has no more' => [20, 0, 100, false, false];
        yield 'an exact total below the hard cap' => [4000, 20, 5000, false, true];
    }

    #[DataProvider('exactPages')]
    public function testSearchResultHasNoMoreAtTheHardCapOrOnAnEmptyPage(int $offset, int $hits, int $total, bool $lowerBound, bool $expected): void
    {
        $result = new SearchResult(array_map($this->hit(...), $hits === 0 ? [] : range(1, $hits)), $total, $lowerBound, 1.0, false, [], 20, $offset);

        self::assertSame($expected, $result->hasMore());
    }

    /** @return iterable<string, array{int, int, int, bool}> offset, hits on the page, total, expected */
    public static function federatedPages(): iterable
    {
        yield 'a page inside the read limit' => [960, 20, 5000, true];
        yield 'the page that ends at the read limit: the next limit() would be refused' => [980, 20, 5000, false];
        yield 'an empty page' => [100, 0, 5000, false];
        yield 'an exact total below the read limit' => [20, 20, 50, true];
    }

    #[DataProvider('federatedPages')]
    public function testFederatedResultHasNoMoreWhereTheNextPageCannotBeAskedFor(int $offset, int $hits, int $total, bool $expected): void
    {
        $merged = array_map(fn(int $id): FederatedHit => new FederatedHit('articles', $this->hit($id), 0.5), $hits === 0 ? [] : range(1, $hits));
        $result = new FederatedResult($merged, [], $total, true, 1.0, [], 20, $offset);

        self::assertSame($expected, $result->hasMore());
    }

    #[DataProvider('pages')]
    public function testSearchResultHasMoreWhenTheWindowEndsBeforeTheTotal(int $offset, int $hits, int $total, bool $lowerBound, bool $expected): void
    {
        $result = new SearchResult(array_map($this->hit(...), range(1, $hits)), $total, $lowerBound, 1.0, false, [], 20, $offset);

        self::assertSame($expected, $result->hasMore());
    }

    #[DataProvider('pages')]
    public function testFederatedResultHasMoreWhenTheWindowEndsBeforeTheTotal(int $offset, int $hits, int $total, bool $lowerBound, bool $expected): void
    {
        $merged = array_map(fn(int $id): FederatedHit => new FederatedHit('articles', $this->hit($id), 0.5), range(1, $hits));
        $result = new FederatedResult($merged, [], $total, $lowerBound, 1.0, [], 20, $offset);

        // a federated search reads 1000 hits of an index at most: past that there is no next page to ask for
        self::assertSame($expected && $offset + 20 < 1000, $result->hasMore());
    }
}

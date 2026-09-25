<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Search;

use Fuzzphony\Core\Search\Hit;
use Fuzzphony\Core\Search\ScoreBreakdown;
use Fuzzphony\Core\Search\SearchResult;
use PHPUnit\Framework\TestCase;

final class SearchResultTest extends TestCase
{
    private function hit(int $id): Hit
    {
        return new Hit($id, 1.0, new ScoreBreakdown());
    }

    public function testIterationYieldsTheHits(): void
    {
        $result = new SearchResult([$this->hit(1), $this->hit(2)], 2, false, 1.2, false, [], 20, 0);

        $iterator = $result->getIterator();
        self::assertInstanceOf(\ArrayIterator::class, $iterator);
        self::assertSame([1, 2], array_map(static fn(Hit $h): int|string => $h->id, iterator_to_array($iterator)));
    }

    public function testCountReturnsTheNumberOfHits(): void
    {
        $result = new SearchResult([$this->hit(1), $this->hit(2), $this->hit(3)], 3, false, 1.2, false, [], 20, 0);

        self::assertCount(3, $result);
        self::assertSame(3, $result->count());
    }

    public function testPageComputesTheOneBasedPageNumber(): void
    {
        $result = new SearchResult([], 100, false, 1.2, false, [], 20, 40);

        self::assertSame(3, $result->page());
    }

    public function testHasMoreWhenTotalIsALowerBound(): void
    {
        $result = new SearchResult([$this->hit(1)], 1, true, 1.2, false, [], 20, 0);

        self::assertTrue($result->hasMore());
    }

    public function testHasMoreWhenMoreHitsRemainAfterThisPage(): void
    {
        $result = new SearchResult([$this->hit(1)], 5, false, 1.2, false, [], 1, 0);

        self::assertTrue($result->hasMore());
    }

    public function testHasNoMoreOnTheLastPage(): void
    {
        $result = new SearchResult([$this->hit(1)], 1, false, 1.2, false, [], 20, 0);

        self::assertFalse($result->hasMore());
    }
}

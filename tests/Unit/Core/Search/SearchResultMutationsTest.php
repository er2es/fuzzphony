<?php

declare(strict_types=1);

namespace Fuzzphony\Tests\Unit\Core\Search;

use Fuzzphony\Core\Search\SearchResult;
use PHPUnit\Framework\TestCase;

final class SearchResultMutationsTest extends TestCase
{
    public function testAnEmptyResultHasNoHitsAndNoFlags(): void
    {
        $result = SearchResult::empty(20, 40);

        self::assertSame([], $result->hits);
        self::assertSame(0, $result->total);
        self::assertFalse($result->totalIsLowerBound, 'nothing was cut off: "0+" would be wrong');
        self::assertFalse($result->usedFuzzy);
        self::assertFalse($result->hasMore());
        self::assertSame([20, 40], [$result->limit, $result->offset]);
    }

    public function testAnEmptyResultTookNoTimeUnlessToldOtherwise(): void
    {
        self::assertSame(0.0, SearchResult::empty(20, 0)->tookMs);
        self::assertSame(2.5, SearchResult::empty(20, 0, [], 2.5)->tookMs);
    }

    public function testTheWarningsOfAnEmptyResultAreAList(): void
    {
        /** @phpstan-ignore argument.type (a list is documented; empty() normalises what it is given) */
        $result = SearchResult::empty(20, 0, [3 => 'cut', 7 => 'removed']);

        self::assertSame(['cut', 'removed'], $result->warnings);
    }
}
